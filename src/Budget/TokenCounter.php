<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

/** Shared token counters (Firestore on Cloud Run, local files for development). */
interface TokenCounter
{
    /**
     * Atomically adds each amount (may be negative) to its counter and returns
     * the new totals, in order. Throws if the counters cannot be updated.
     *
     * @param list<array{id: string, amount: int, expireAt: int}> $changes
     * @return list<int>
     */
    public function add(array $changes): array;
}
