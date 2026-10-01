<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

final class OutputCheck
{
    /**
     * @param list<string> $blockedReasons non-empty when the whole reply was replaced
     * @param list<string> $redactions kinds of values that were masked
     */
    public function __construct(
        public readonly string $text,
        public readonly array $blockedReasons = [],
        public readonly array $redactions = [],
    ) {
    }

    public function wasBlocked(): bool
    {
        return $this->blockedReasons !== [];
    }
}
