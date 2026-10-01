<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

final class InputCheck
{
    /** @param list<string> $flags */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $text,
        public readonly ?string $rejectionMessage = null,
        public readonly array $flags = [],
    ) {
    }
}
