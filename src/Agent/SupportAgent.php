<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Bitenxt\SupportAgent\Session\ChatSession;

/**
 * Manual tool-use loop: send the conversation, run any tool calls, repeat
 * until Claude answers. History is append-only and only committed to the
 * session when the turn finishes cleanly, so a failed turn leaves no
 * half-finished exchange behind.
 */
final class SupportAgent
{
    public const MAX_TOOL_ROUNDS = 6;
    public const FALLBACK_REPLY = "Sorry, I couldn't complete that just now. Please try again in a moment, "
        . 'or ask me to connect you with our support team.';

    public function __construct(
        private readonly ClaudeGateway $claude,
        private readonly string $systemPrompt,
    ) {
    }

    /** @return array{reply: string, stop_reason: string} */
    public function respond(ChatSession $session, string $userText, SupportTools $tools): array
    {
        $messages = $session->messages;
        $messages[] = ['role' => 'user', 'content' => $userText];
        $definitions = SupportTools::definitions();

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->claude->create($this->systemPrompt, $definitions, $messages);

            if ($response->stopReason === 'refusal') {
                // Nothing from this turn is kept; the customer can rephrase.
                return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => 'refusal'];
            }

            $messages[] = ['role' => 'assistant', 'content' => self::contentToArray($response)];

            if ($response->stopReason !== 'tool_use') {
                $text = self::text($response);
                if ($text === '') {
                    return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => (string) $response->stopReason];
                }
                $session->messages = $messages;

                return ['reply' => $text, 'stop_reason' => (string) $response->stopReason];
            }

            if ($round === self::MAX_TOOL_ROUNDS) {
                break; // never leave an unanswered tool_use in the saved history
            }

            $results = [];
            foreach ($response->content as $block) {
                if ($block instanceof BetaToolUseBlock) {
                    [$content, $isError] = $tools->execute($block->name, (array) $block->input);
                    $results[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $block->id,
                        'content' => $content,
                        'is_error' => $isError,
                    ];
                }
            }
            // All results for one assistant turn go back in a single user message.
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['reply' => self::FALLBACK_REPLY, 'stop_reason' => 'max_tool_rounds'];
    }

    /** @return list<array<string, mixed>> the assistant content exactly as returned, in wire format */
    private static function contentToArray(BetaMessage $response): array
    {
        $content = json_decode((string) json_encode($response->content), true) ?: [];
        $restored = ChatSession::restoreEmptyObjects([['content' => $content]]);

        return $restored[0]['content'];
    }

    private static function text(BetaMessage $response): string
    {
        $parts = [];
        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
