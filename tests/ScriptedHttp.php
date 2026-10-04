<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

/** Fake HTTP transport for OpenAI-compatible providers: replays queued responses, records requests. */
final class ScriptedHttp
{
    /** @var list<array{url: string, headers: list<string>, body: array}> */
    public array $requests = [];

    /** @param list<array{0: int, 1: array|string}> $responses */
    public function __construct(private array $responses)
    {
    }

    public function __invoke(string $url, array $headers, string $body, int $timeout): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        [$status, $payload] = array_shift($this->responses) ?? throw new \LogicException('No scripted HTTP response left');

        return [$status, is_string($payload) ? $payload : json_encode($payload)];
    }

    public static function text(string $text, int $in = 100, int $out = 20): array
    {
        return [200, [
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ]];
    }

    public static function toolCall(string $name, array $args, ?string $id = 'call_1', string $finish = 'tool_calls'): array
    {
        $call = ['type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]];
        if ($id !== null) {
            $call['id'] = $id;
        }

        return [200, [
            'choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15],
        ]];
    }
}
