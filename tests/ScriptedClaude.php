<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Anthropic\Beta\Messages\BetaMessage;
use Bitenxt\SupportAgent\Agent\ClaudeGateway;

/** Replays scripted Claude responses and records every request it receives. */
final class ScriptedClaude implements ClaudeGateway
{
    /** @var list<array> */
    public array $requests = [];

    /** @param list<array> $responses raw API-shaped messages */
    public function __construct(private array $responses)
    {
    }

    public function create(string $system, array $tools, array $messages): BetaMessage
    {
        $this->requests[] = ['system' => $system, 'tools' => $tools, 'messages' => $messages];
        $next = array_shift($this->responses) ?? throw new \LogicException('No scripted response left');

        return BetaMessage::fromArray($next + [
            'id' => 'msg_' . count($this->requests),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ]);
    }

    public static function text(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    public static function toolCall(string $name, array $input, string $id = 'toolu_1'): array
    {
        return ['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-' . $id],
            ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input === [] ? new \stdClass() : $input],
        ]];
    }
}
