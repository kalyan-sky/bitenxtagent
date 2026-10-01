<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Chat;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SupportTools;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Guardrails\RateLimiter;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Magento\CustomerDataSource;
use Bitenxt\SupportAgent\Magento\MagentoAuthException;
use Bitenxt\SupportAgent\Magento\MagentoException;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Session\SessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;

/**
 * One chat request, end to end:
 * rate limit → input guard → identity → agent + tools → output guard → save.
 */
final class ChatService
{
    public const BUSY_REPLY = "I'm getting a lot of messages right now. Please wait a minute and try again.";
    public const SESSION_FULL_REPLY = 'This conversation has reached its length limit. Please start a new chat, '
        . 'or ask for our support team if you still need help.';

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly RateLimiter $rateLimiter,
        private readonly InputGuard $inputGuard,
        private readonly OutputGuard $outputGuard,
        private readonly SupportAgent $agent,
        private readonly CustomerDataSource $magento,
        private readonly KnowledgeBase $knowledge,
        private readonly HandoffNotifier $handoff,
        private readonly Logger $logger,
        private readonly int $maxTurnsPerSession,
    ) {
    }

    /**
     * @return array{session_id: string, reply: string, signed_in: bool}
     */
    public function handle(?string $sessionId, string $message, ?string $customerToken, string $clientIp): array
    {
        if (!$this->rateLimiter->hit('ip:' . $clientIp)) {
            $this->logger->log('rate_limited', ['ip' => Logger::pseudonym($clientIp)]);

            return ['session_id' => (string) $sessionId, 'reply' => self::BUSY_REPLY, 'signed_in' => false];
        }

        $session = $this->sessions->load($sessionId);
        $session = $this->bindIdentity($session, $customerToken);
        $token = $session->isAuthenticated() ? $customerToken : null;

        if (!$this->rateLimiter->hit('session:' . $session->id)
            || ($session->customerId !== '' && !$this->rateLimiter->hit('customer:' . $session->customerId))) {
            return $this->reply($session, self::BUSY_REPLY);
        }

        $input = $this->inputGuard->check($message);
        if (!$input->allowed) {
            return $this->reply($session, (string) $input->rejectionMessage);
        }
        if ($input->flags !== []) {
            $this->logger->log('suspicious_input', ['session' => $session->id, 'flags' => $input->flags]);
        }

        if ($session->userTurns >= $this->maxTurnsPerSession) {
            return $this->reply($session, self::SESSION_FULL_REPLY);
        }

        $historyLength = count($session->messages);
        $tools = new SupportTools($session, $token, $this->magento, $this->knowledge, $this->handoff, $this->logger);

        try {
            $result = $this->agent->respond($session, $input->text, $tools);
        } catch (RateLimitException | APIConnectionException $e) {
            $this->logger->log('claude_unavailable', ['session' => $session->id, 'error' => $e::class]);
            $this->sessions->save($session);

            return $this->reply($session, SupportAgent::FALLBACK_REPLY);
        } catch (APIStatusException $e) {
            $this->logger->log('claude_error', ['session' => $session->id, 'error' => $e::class, 'status' => $e->getCode()]);
            $this->sessions->save($session);

            return $this->reply($session, SupportAgent::FALLBACK_REPLY);
        }

        $allowed = $session->safeValues;
        if ($session->customerEmail !== '') {
            $allowed[] = $session->customerEmail;
        }
        $output = $this->outputGuard->filter($result['reply'], $allowed);

        if ($output->wasBlocked()) {
            // Drop the whole turn so the blocked reply cannot be built on later.
            $session->messages = array_slice($session->messages, 0, $historyLength);
            $this->logger->log('reply_blocked', ['session' => $session->id, 'reasons' => $output->blockedReasons]);
        } elseif ($output->redactions !== []) {
            $this->logger->log('reply_redacted', ['session' => $session->id, 'kinds' => $output->redactions]);
        }

        $session->userTurns++;
        $this->sessions->save($session);
        $this->logger->log('turn', [
            'session' => $session->id,
            'customer' => Logger::pseudonym($session->customerId),
            'stop_reason' => $result['stop_reason'],
            'turn' => $session->userTurns,
        ]);

        return $this->reply($session, $output->text);
    }

    /**
     * Ties the session to whoever holds the Magento token on *this* request.
     * A session ID alone never grants access to a signed-in conversation:
     * a missing, different or invalid token starts a fresh session.
     */
    private function bindIdentity(ChatSession $session, ?string $token): ChatSession
    {
        $tokenHash = ($token !== null && $token !== '') ? hash('sha256', $token) : '';

        if ($session->tokenHash !== $tokenHash && ($session->tokenHash !== '' || $session->messages !== [])) {
            $this->logger->log('session_reset', ['session' => $session->id, 'reason' => 'identity_changed']);
            $session = $this->sessions->load(null);
        }

        if ($tokenHash === '') {
            return $session;
        }

        try {
            $customer = $this->magento->currentCustomer($token);
        } catch (MagentoAuthException) {
            $this->logger->log('token_rejected', ['session' => $session->id]);

            return $session->tokenHash === '' ? $session : $this->sessions->load(null);
        } catch (MagentoException $e) {
            // Magento is down: carry on unauthenticated rather than fail the chat.
            $this->logger->log('magento_error', ['session' => $session->id, 'detail' => $e->getMessage()]);

            return $session->tokenHash === '' ? $session : $this->sessions->load(null);
        }

        $session->tokenHash = $tokenHash;
        $session->customerId = $customer['customer_id'];
        $session->customerFirstname = $customer['firstname'];
        $session->customerEmail = $customer['email'];

        return $session;
    }

    /** @return array{session_id: string, reply: string, signed_in: bool} */
    private function reply(ChatSession $session, string $text): array
    {
        return ['session_id' => $session->id, 'reply' => $text, 'signed_in' => $session->isAuthenticated()];
    }
}
