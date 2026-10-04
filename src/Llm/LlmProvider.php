<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

/**
 * One AI provider (Claude, Gemini, OpenAI, ...). Each provider keeps the
 * conversation in its own message format; the agent only builds messages
 * through these methods and appends replies unchanged.
 */
interface LlmProvider
{
    /** Configured name, e.g. "gemini" or "claude" (used in logs and budgets). */
    public function name(): string;

    public function model(): string;

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, strict?: bool}> $tools
     * @param list<array<string, mixed>> $messages conversation so far, in this provider's format
     * @throws LlmUnavailableException
     */
    public function complete(string $system, array $tools, array $messages, int $maxOutputTokens): LlmResponse;

    /** @return array<string, mixed> */
    public function userMessage(string $text): array;

    /** A plain-text assistant turn, used to seed history from the visible transcript. @return array<string, mixed> */
    public function assistantMessage(string $text): array;

    /**
     * Messages carrying tool results back to the model.
     *
     * @param list<array{id: string, name: string, content: string, isError: bool}> $results
     * @return list<array<string, mixed>>
     */
    public function toolResultMessages(array $results): array;
}
