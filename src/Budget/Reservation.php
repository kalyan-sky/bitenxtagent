<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

/** Tokens held against the limits for one AI call, corrected to real usage afterwards. */
final class Reservation
{
    /** @param list<array{id: string, expireAt: int}> $counters */
    public function __construct(
        public readonly array $counters,
        public readonly int $estimate,
    ) {
    }
}
