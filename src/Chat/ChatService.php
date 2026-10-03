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
 * rate limit → verify login token → session → input guard → agent + tools → output guard → save.
 */
final class ChatService
{
    public const BUSY_REPLY = "I'm getting a lot of messages right now. Please wait a minute and try again.";
    public const LOGIN_REQUIRED_REPLY = 'Please log in to your BiteNXT Pro account to use support chat.';
    public const UNAVAILABLE_REPLY = "We can't verify your account right now. Please try again in a few minutes.";
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
     * Only logged-in Pro customers can chat. A request without a valid Magento
     * customer token is refused before any session is loaded or Claude is called.
     *
     * @return array{status: int, session_id?: string, reply: string, error?: string}
     */
    public function handle(?string $sessionId, string $message, ?string $customerToken, string $clientIp): array
    {
        if (!$this->rateLimiter->hit('ip:' . $clientIp)) {
            $this->logger->log('rate_limited', ['ip' => Logger::pseudonym($clientIp)]);

            return ['status' => 429, 'error' => 'rate_limited', 'reply' => self::BUSY_REPLY];
        }

        if ($customerToken === null || $customerToken === '') {
            return self::loginRequired();
        }
        try {
            $customer = $this->magento->currentCustomer($customerToken);
        } catch (MagentoAuthException) {
            $this->logger->log('token_rejected', ['ip' => Logger::pseudonym($clientIp)]);

            return self::loginRequired();
        } catch (MagentoException $e) {
            $this->logger->log('magento_error', ['detail' => $e->getMessage()]);

            return ['status' => 503, 'error' => 'unavailable', 'reply' => self::UNAVAILABLE_REPLY];
        }

        $session = $this->bindIdentity($this->sessions->load($sessionId), $customerToken, $customer);

        if (!$this->rateLimiter->hit('session:' . $session->id)
            || !$this->rateLimiter->hit('customer:' . $session->customerId)) {
            return $this->reply($session, self::BUSY_REPLY, 429);
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
        $tools = new SupportTools($session, $customerToken, $this->magento, $this->knowledge, $this->handoff, $this->logger);

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
     * Ties the session to the customer verified on *this* request. A session
     * ID alone never grants access to a conversation: a session that belongs
     * to anyone else is replaced with a fresh one. The same customer logging
     * in again (new token) keeps their conversation.
     *
     * @param array{customer_id: string, firstname: string, email: string, business_name: string} $customer
     */
    private function bindIdentity(ChatSession $session, string $token, array $customer): ChatSession
    {
        $owner = $customer['customer_id'] !== '' ? 'id:' . $customer['customer_id'] : 'email:' . strtolower($customer['email']);
        $sessionOwner = $session->customerId !== '' ? 'id:' . $session->customerId
            : ($session->customerEmail !== '' ? 'email:' . strtolower($session->customerEmail) : '');

        if ($sessionOwner !== $owner) {
            if ($sessionOwner !== '') {
                $this->logger->log('session_reset', ['session' => $session->id, 'reason' => 'different_customer']);
            }
            $session = ChatSession::start();
        }

        $session->tokenHash = hash('sha256', $token);
        $session->customerId = $customer['customer_id'];
        $session->customerFirstname = $customer['firstname'];
        $session->customerEmail = $customer['email'];

        return $session;
    }

    /** @return array{status: int, error: string, reply: string} */
    private static function loginRequired(): array
    {
        return ['status' => 401, 'error' => 'login_required', 'reply' => self::LOGIN_REQUIRED_REPLY];
    }

    /** @return array{status: int, session_id: string, reply: string} */
    private function reply(ChatSession $session, string $text, int $status = 200): array
    {
        return ['status' => $status, 'session_id' => $session->id, 'reply' => $text];
    }
}
