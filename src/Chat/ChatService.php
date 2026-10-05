<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Chat;

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
use Bitenxt\SupportAgent\Support\Cache;
use Bitenxt\SupportAgent\Support\Logger;
use Bitenxt\SupportAgent\Support\Timing;

/**
 * Chat for logged-in Pro customers, Amazon-style: each customer has one
 * continuous thread. They never pick or manage conversations; the server finds
 * their current one. Behind the scenes the thread is split into
 * "conversations" (the context Claude sees): after a quiet spell, or when one
 * gets long, the next message starts a fresh conversation, while the customer
 * still sees all earlier messages in the same window.
 *
 * Per message: rate limit → verify login token → current conversation → input
 * guard → fast path or agent + tools → output guard → transcript → save.
 */
final class ChatService
{
    public const BUSY_REPLY = "I'm getting a lot of messages right now. Please wait a minute and try again.";
    public const LOGIN_REQUIRED_REPLY = 'Please log in to your BiteNXT Pro account to use support chat.';
    public const UNAVAILABLE_REPLY = "We can't verify your account right now. Please try again in a few minutes.";
    public const HISTORY_LIMIT = 200;
    /** How long a verified login is trusted before Magento is asked again. */
    public const IDENTITY_CACHE_SECONDS = 300;

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
        private readonly int $maxTurnsPerConversation,
        private readonly int $conversationIdleSeconds = 1800,
        private readonly ?FastPath $fastPath = null,
    ) {
    }

    /**
     * Handles one customer message. A request without a valid Magento
     * customer token is refused before anything is loaded or Claude is called.
     *
     * @return array{status: int, reply: string, at?: int, error?: string}
     */
    public function handle(string $message, ?string $customerToken, string $clientIp): array
    {
        Timing::reset();
        $started = hrtime(true);
        $customer = $this->authenticate($customerToken, $clientIp);
        if (isset($customer['status'])) {
            return $customer;
        }
        $owner = self::ownerKey($customer);

        if (!$this->rateLimiter->hit('customer:' . $owner)) {
            return ['status' => 429, 'error' => 'rate_limited', 'reply' => self::BUSY_REPLY];
        }

        $input = $this->inputGuard->check($message);
        if (!$input->allowed) {
            return ['status' => 400, 'error' => 'invalid_message', 'reply' => (string) $input->rejectionMessage];
        }

        $session = $this->currentConversation($owner);
        $session->tokenHash = hash('sha256', (string) $customerToken);
        $session->customerId = $customer['customer_id'];
        $session->customerFirstname = $customer['firstname'];
        $session->customerEmail = $customer['email'];

        if ($input->flags !== []) {
            $this->logger->log('suspicious_input', ['session' => $session->id, 'flags' => $input->flags]);
        }

        // Snapshot, so a blocked reply can be undone even if a different
        // provider answered (and replaced the AI history).
        [$previousMessages, $previousProvider] = [$session->messages, $session->provider];
        $tools = new SupportTools($session, $customerToken, $this->magento, $this->knowledge, $this->handoff, $this->logger);

        // Simple order lookups are answered from a template, without the AI.
        $fast = $input->flags === [] ? $this->fastPath?->answer($input->text, $tools) : null;
        if ($fast !== null) {
            $result = ['reply' => $fast, 'stop_reason' => 'fast_path', 'provider' => 'fast_path'];
            // The AI never saw this turn: its next call starts from the transcript instead.
            [$session->messages, $session->provider] = [[], 'fast_path'];
        } else {
            // Provider errors, fallback and token limits are all handled inside the agent.
            try {
                $result = $this->agent->respond($session, $input->text, $tools, $owner);
            } catch (\Throwable $e) {
                $this->logger->log('agent_exception', ['session' => $session->id, 'class' => $e::class,
                    'detail' => mb_substr($e->getMessage(), 0, 500), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
                [$session->messages, $session->provider] = [$previousMessages, $previousProvider];
                $result = ['reply' => SupportAgent::FALLBACK_REPLY, 'stop_reason' => 'exception'];
            }
        }
        if ($result['stop_reason'] === 'all_providers_failed') {
            $this->logger->log('llm_all_failed', ['session' => $session->id]);
        }

        $allowed = $session->safeValues;
        if ($session->customerEmail !== '') {
            $allowed[] = $session->customerEmail;
        }
        $output = $this->outputGuard->filter($result['reply'], $allowed);

        if ($output->wasBlocked()) {
            // Drop the whole turn so the blocked reply cannot be built on later.
            [$session->messages, $session->provider] = [$previousMessages, $previousProvider];
            $this->logger->log('reply_blocked', ['session' => $session->id, 'reasons' => $output->blockedReasons]);
        } elseif ($output->redactions !== []) {
            $this->logger->log('reply_redacted', ['session' => $session->id, 'kinds' => $output->redactions]);
        }

        $session->userTurns++;
        $this->logger->log('turn', [
            'session' => $session->id,
            'customer' => Logger::pseudonym($owner),
            'stop_reason' => $result['stop_reason'],
            'provider' => $result['provider'] ?? '',
            'turn' => $session->userTurns,
        ]);

        $response = $this->finish($session, $input->text, $output->text);
        $this->logger->log('timing', ['session' => $session->id, 'path' => $result['stop_reason'] === 'fast_path' ? 'fast_path' : 'ai',
            'total_ms' => (int) ((hrtime(true) - $started) / 1e6)] + Timing::summary());

        return $response;
    }

    /**
     * 👍/👎 on an answer. Logged (no message text) so unhelpful answers can be
     * found in the conversation by session and time.
     *
     * @return array{status: int, ok?: bool, reply?: string, error?: string}
     */
    public function feedback(?string $customerToken, string $clientIp, string $rating, int $at): array
    {
        $customer = $this->authenticate($customerToken, $clientIp);
        if (isset($customer['status'])) {
            return $customer;
        }
        if (!in_array($rating, ['up', 'down'], true)) {
            return ['status' => 400, 'error' => 'invalid_rating'];
        }
        $owner = self::ownerKey($customer);
        $latest = $this->sessions->findByOwner($owner, 1)[0] ?? null;
        $this->logger->log('feedback', [
            'rating' => $rating,
            'session' => $latest['id'] ?? '',
            'message_at' => $at,
            'customer' => Logger::pseudonym($owner),
        ]);

        return ['status' => 200, 'ok' => true];
    }

    /**
     * The customer's chat thread, oldest first: exactly the messages they were
     * shown, across all their conversations in the retention period.
     *
     * @return array{status: int, messages?: list<array{role: string, text: string, at: int}>, reply?: string, error?: string}
     */
    public function history(?string $customerToken, string $clientIp): array
    {
        $customer = $this->authenticate($customerToken, $clientIp);
        if (isset($customer['status'])) {
            return $customer;
        }
        $owner = self::ownerKey($customer);

        $messages = [];
        foreach ($this->sessions->findByOwner($owner, 50) as $summary) {
            $session = $this->sessions->load($summary['id']);
            if ($session->id !== $summary['id'] || $session->ownerKey() !== $owner) {
                continue; // expired meanwhile, or not this customer's
            }
            $messages = array_merge($session->transcript, $messages);
            if (count($messages) >= self::HISTORY_LIMIT) {
                break;
            }
        }
        usort($messages, static fn ($a, $b) => $a['at'] <=> $b['at']);

        return ['status' => 200, 'messages' => array_slice($messages, -self::HISTORY_LIMIT)];
    }

    /**
     * Verifies the Magento customer token. Returns the customer, or an error
     * response (which has a `status` key).
     *
     * @return array<string, mixed>
     */
    private function authenticate(?string $customerToken, string $clientIp): array
    {
        if (!$this->rateLimiter->hit('ip:' . $clientIp)) {
            $this->logger->log('rate_limited', ['ip' => Logger::pseudonym($clientIp)]);

            return ['status' => 429, 'error' => 'rate_limited', 'reply' => self::BUSY_REPLY];
        }
        if ($customerToken === null || $customerToken === '') {
            return self::loginRequired();
        }
        // Verified logins are remembered for a few minutes (keyed by a hash of
        // the token, never the token itself) to save a Magento call per message.
        $cacheKey = 'customer:' . hash('sha256', $customerToken);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['customer_id'])) {
            return $cached;
        }
        try {
            $customer = $this->magento->currentCustomer($customerToken);
            Cache::set($cacheKey, $customer, self::IDENTITY_CACHE_SECONDS);

            return $customer;
        } catch (MagentoAuthException) {
            $this->logger->log('token_rejected', ['ip' => Logger::pseudonym($clientIp)]);

            return self::loginRequired();
        } catch (MagentoException $e) {
            $this->logger->log('magento_error', ['detail' => $e->getMessage()]);

            return ['status' => 503, 'error' => 'unavailable', 'reply' => self::UNAVAILABLE_REPLY];
        }
    }

    /**
     * The customer's most recent conversation if it is still active, otherwise
     * a new one. Conversations are only ever found by the verified customer's
     * key, so nobody can reach another customer's chat.
     */
    private function currentConversation(string $owner): ChatSession
    {
        $latest = $this->sessions->findByOwner($owner, 1)[0] ?? null;
        if ($latest !== null && $latest['updatedAt'] >= time() - $this->conversationIdleSeconds) {
            $session = $this->sessions->load($latest['id']);
            if ($session->id === $latest['id'] && $session->ownerKey() === $owner
                && $session->userTurns < $this->maxTurnsPerConversation) {
                return $session;
            }
        }

        return ChatSession::start();
    }

    /** Records what the customer saw, saves, and builds the response. */
    private function finish(ChatSession $session, string $userText, string $reply): array
    {
        $session->addTranscript('user', $userText);
        $session->addTranscript('assistant', $reply);
        $this->sessions->save($session);

        return ['status' => 200, 'reply' => $reply, 'at' => time()];
    }

    /** @param array<string, mixed> $customer */
    private static function ownerKey(array $customer): string
    {
        return ChatSession::ownerKeyFor((string) $customer['customer_id'], (string) $customer['email']);
    }

    /** @return array{status: int, error: string, reply: string} */
    private static function loginRequired(): array
    {
        return ['status' => 401, 'error' => 'login_required', 'reply' => self::LOGIN_REQUIRED_REPLY];
    }
}
