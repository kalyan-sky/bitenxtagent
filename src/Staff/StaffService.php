<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Staff;

use Bitenxt\SupportAgent\Guardrails\RateLimiter;
use Bitenxt\SupportAgent\Session\SessionStore;
use Bitenxt\SupportAgent\Support\Logger;

/**
 * Read-only access for the support team to chat transcripts (what customers
 * saw, never Claude's internal tool data). Protected by STAFF_ACCESS_KEY, a
 * long random secret kept in Secret Manager; disabled when it is not set.
 * Every view is written to the audit log.
 */
final class StaffService
{
    private const MIN_KEY_LENGTH = 24;

    public function __construct(
        private readonly SessionStore $sessions,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger,
        private readonly string $accessKey,
    ) {
    }

    public function enabled(): bool
    {
        return strlen($this->accessKey) >= self::MIN_KEY_LENGTH;
    }

    /** @return array{status: int, error: string}|null null when the key is valid */
    public function authorize(?string $key, string $clientIp): ?array
    {
        if (!$this->enabled()) {
            return ['status' => 404, 'error' => 'not_found'];
        }
        if (!$this->rateLimiter->hit('staff-ip:' . $clientIp)) {
            return ['status' => 429, 'error' => 'rate_limited'];
        }
        if ($key === null || !hash_equals($this->accessKey, $key)) {
            $this->logger->log('staff_denied', ['ip' => Logger::pseudonym($clientIp)]);

            return ['status' => 401, 'error' => 'invalid_staff_key'];
        }

        return null;
    }

    /** Recent conversations that were handed off to the team. */
    public function escalated(string $clientIp): array
    {
        $this->logger->log('staff_view', ['view' => 'escalated', 'ip' => Logger::pseudonym($clientIp)]);

        return ['status' => 200, 'conversations' => array_map(self::listItem(...), $this->sessions->find('escalated', true, 50))];
    }

    /** All conversations of one customer (by account email), newest first, with transcripts. */
    public function customer(string $email, string $clientIp): array
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 400, 'error' => 'invalid_email'];
        }
        $this->logger->log('staff_view', ['view' => 'customer', 'customer' => Logger::pseudonym($email), 'ip' => Logger::pseudonym($clientIp)]);

        $conversations = [];
        foreach ($this->sessions->find('customerEmail', $email, 20) as $summary) {
            $session = $this->sessions->load($summary['id']);
            if ($session->id === $summary['id']) {
                $conversations[] = self::listItem($summary) + ['transcript' => $session->transcript];
            }
        }

        return ['status' => 200, 'customer_email' => $email, 'conversations' => $conversations];
    }

    /** One conversation's transcript (e.g. from the link in a handoff message). */
    public function conversation(string $id, string $clientIp): array
    {
        $session = $this->sessions->load($id);
        if ($session->id !== $id || $session->transcript === []) {
            return ['status' => 404, 'error' => 'not_found'];
        }
        $this->logger->log('staff_view', ['view' => 'conversation', 'session' => $id, 'ip' => Logger::pseudonym($clientIp)]);

        return ['status' => 200, 'conversation' => self::listItem($session->summary()) + ['transcript' => $session->transcript]];
    }

    /** @param array<string, mixed> $summary */
    private static function listItem(array $summary): array
    {
        return [
            'id' => $summary['id'],
            'customer_email' => $summary['customerEmail'],
            'title' => $summary['title'],
            'updated_at' => $summary['updatedAt'],
            'message_count' => $summary['messageCount'],
            'escalated' => $summary['escalated'],
        ];
    }
}
