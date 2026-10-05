<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Llm;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Bitenxt\SupportAgent\Agent\ClaudeGateway;
use Bitenxt\SupportAgent\Session\ChatSession;

/**
 * Claude through the official Anthropic SDK. History is kept in Anthropic's
 * message format, including thinking blocks, which are replayed unchanged.
 */
final class AnthropicProvider implements LlmProvider
{
    /** @param list<ClaudeGateway> $gateways one per API key, tried in order on rate-limit or key errors */
    public function __construct(
        private readonly string $name,
        private readonly string $model,
        private readonly array $gateways,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(string $system, array $tools, array $messages, int $maxOutputTokens): LlmResponse
    {
        $lastError = null;
        foreach ($this->gateways as $gateway) {
            try {
                return self::toResponse($gateway->create($system, $tools, $messages, $maxOutputTokens));
            } catch (RateLimitException | AuthenticationException | PermissionDeniedException $e) {
                $lastError = $e; // this key is throttled or rejected: try the next key
            } catch (AnthropicException $e) {
                throw new LlmUnavailableException($this->name . ': ' . $e::class . ' ' . $e->getMessage(), 0, $e);
            }
        }

        throw new LlmUnavailableException(
            $this->name . ': all API keys failed' . ($lastError ? ' (' . $lastError::class . ')' : ''),
            0,
            $lastError,
        );
    }

    public function userMessage(string $text): array
    {
        return ['role' => 'user', 'content' => $text];
    }

    public function assistantMessage(string $text): array
    {
        return ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function toolResultMessages(array $results): array
    {
        // All results for one assistant turn go back in a single user message.
        return [[
            'role' => 'user',
            'content' => array_map(static fn (array $r) => [
                'type' => 'tool_result',
                'tool_use_id' => $r['id'],
                'content' => $r['content'],
                'is_error' => $r['isError'],
            ], $results),
        ]];
    }

    private static function toResponse(BetaMessage $message): LlmResponse
    {
        $text = [];
        $calls = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text[] = $block->text;
            } elseif ($block instanceof BetaToolUseBlock) {
                // Array access: the typed ->input accessor throws when the input is an empty object.
                $calls[] = new ToolCall($block->id, $block->name, (array) ($block['input'] ?? []));
            }
        }

        // Keep the content exactly as returned (wire format), so thinking
        // blocks and their signatures are replayed byte for byte.
        $content = json_decode((string) json_encode($message->content), true) ?: [];
        $content = ChatSession::restoreEmptyObjects([['content' => $content]])[0]['content'];

        $usage = $message->usage;
        $input = $usage->inputTokens + (int) $usage->cacheReadInputTokens + (int) $usage->cacheCreationInputTokens;

        return new LlmResponse(
            stopReason: match ((string) $message->stopReason) {
                'tool_use' => LlmResponse::TOOL_USE,
                'refusal' => LlmResponse::REFUSAL,
                'max_tokens' => LlmResponse::MAX_TOKENS,
                default => LlmResponse::END,
            },
            text: trim(implode("\n\n", $text)),
            toolCalls: $calls,
            assistantMessage: ['role' => 'assistant', 'content' => $content],
            inputTokens: $input,
            outputTokens: $usage->outputTokens,
        );
    }
}
