<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

/** A tool the model asked to run. */
final class ToolCall
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {
    }
}
