<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

/** One model reply, in a provider-neutral shape. */
final class LlmResponse
{
    public const END = 'end_turn';
    public const TOOL_USE = 'tool_use';
    public const MAX_TOKENS = 'max_tokens';
    public const REFUSAL = 'refusal';

    /**
     * @param list<ToolCall> $toolCalls
     * @param array<string, mixed> $assistantMessage the reply in the provider's own message format,
     *                                               appended to that provider's history unchanged
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $assistantMessage,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
