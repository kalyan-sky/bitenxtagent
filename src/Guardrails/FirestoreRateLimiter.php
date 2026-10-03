<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;

/**
 * Fixed-window counters in Firestore, so limits hold across all Cloud Run
 * instances. One atomic commit bumps the minute and day counters together.
 * Counter documents carry `expireAt` for TTL cleanup.
 */
final class FirestoreRateLimiter implements RateLimiter
{
    public function __construct(
        private readonly FirestoreClient $firestore,
        private readonly int $perMinute,
        private readonly int $perDay,
        private readonly string $collection = 'chat_ratelimits',
    ) {
    }

    public function hit(string $key): bool
    {
        $now = time();
        $hash = substr(hash('sha256', $key), 0, 32);
        $minute = intdiv($now, 60);
        $day = intdiv($now, 86400);

        try {
            [$minuteCount, $dayCount] = $this->firestore->increment([
                ['collection' => $this->collection, 'id' => "m{$minute}-{$hash}", 'expireAt' => ($minute + 2) * 60],
                ['collection' => $this->collection, 'id' => "d{$day}-{$hash}", 'expireAt' => ($day + 2) * 86400],
            ]);
        } catch (\RuntimeException $e) {
            // Fail open: a Firestore blip should not take the chat down. The
            // per-session turn cap and Claude's own limits still apply.
            error_log('[support-agent] rate limiter unavailable: ' . $e->getMessage());

            return true;
        }

        return $minuteCount <= $this->perMinute && $dayCount <= $this->perDay;
    }
}
