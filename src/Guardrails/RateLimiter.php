<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

/**
 * Per-minute and per-day request limits per key (IP, session, customer).
 * Limits abuse, cost, and brute-force guessing of order numbers.
 */
interface RateLimiter
{
    /** Records a hit and returns false if the key is over either limit. */
    public function hit(string $key): bool;
}
