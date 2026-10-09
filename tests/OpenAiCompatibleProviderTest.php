<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Agent\SupportTools;
use Bitenxt\SupportAgent\Llm\LlmResponse;
use Bitenxt\SupportAgent\Llm\LlmUnavailableException;
use Bitenxt\SupportAgent\Llm\OpenAiCompatibleProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleProviderTest extends TestCase
{
    private function provider(ScriptedHttp $http, array $keys = ['key-1']): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('gemini', 'gemini-test-model', 'https://generativelanguage.googleapis.com/v1beta/openai/', $keys, 'max_tokens', 30, $http);
    }

    public function testRequestShape(): void
    {
        $http = new ScriptedHttp([ScriptedHttp::text('Hi!')]);
        $this->provider($http)->complete('SYSTEM', SupportTools::definitions(), [['role' => 'user', 'content' => 'hello']], 1024);

        $request = $http->requests[0];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', $request['url']);
        self::assertContains('Authorization: Bearer key-1', $request['headers']);
        self::assertSame('gemini-test-model', $request['body']['model']);
        self::assertSame(1024, $request['body']['max_tokens']);
        self::assertSame(['role' => 'system', 'content' => 'SYSTEM'], $request['body']['messages'][0]);
        self::assertSame('function', $request['body']['tools'][0]['type']);
        $json = json_encode($request['body']['tools']);
        self::assertStringNotContainsString('additionalProperties', $json, 'unsupported schema keywords are stripped');
        self::assertStringNotContainsString('"strict"', $json);
    }

    public function testReasoningOption(): void
    {
        self::assertArrayNotHasKey('reasoning', $this->bodyWith(''), 'not sent unless configured');
        self::assertSame(['enabled' => false], $this->bodyWith('off')['reasoning']);
        self::assertSame(['effort' => 'low'], $this->bodyWith('low')['reasoning']);
    }

    private function bodyWith(string $reasoning): array
    {
        $http = new ScriptedHttp([ScriptedHttp::text('Hi!')]);
        (new OpenAiCompatibleProvider('openrouter', 'deepseek/deepseek-v4.1-flash', 'https://openrouter.ai/api/v1/', ['k'], 'max_tokens', 30, $http, $reasoning))
            ->complete('S', [], [['role' => 'user', 'content' => 'x']], 100);

        return $http->requests[0]['body'];
    }

    public function testTextReplyAndUsage(): void
    {
        $response = $this->provider(new ScriptedHttp([ScriptedHttp::text('Order shipped.', 300, 40)]))
            ->complete('S', [], [['role' => 'user', 'content' => 'x']], 100);

        self::assertSame(LlmResponse::END, $response->stopReason);
        self::assertSame('Order shipped.', $response->text);
        self::assertSame(340, $response->totalTokens());
    }

    public function testToolCallsEvenWhenFinishReasonSaysStopAndIdsAreMissing(): void
    {
        $response = $this->provider(new ScriptedHttp([ScriptedHttp::toolCall('get_order_status', ['order_number' => '000000101'], null, 'stop')]))
            ->complete('S', [], [['role' => 'user', 'content' => 'x']], 100);

        self::assertSame(LlmResponse::TOOL_USE, $response->stopReason);
        self::assertSame('get_order_status', $response->toolCalls[0]->name);
        self::assertSame(['order_number' => '000000101'], $response->toolCalls[0]->input);
        $id = $response->toolCalls[0]->id;
        self::assertNotSame('', $id, 'a missing id is generated');
        self::assertSame($id, $response->assistantMessage['tool_calls'][0]['id'], 'and stored in history so the tool result matches');
    }

    public function testRotatesToTheNextKeyOnRateLimitOrBadKey(): void
    {
        $http = new ScriptedHttp([[429, ['error' => ['message' => 'quota']]], [401, 'bad key'], ScriptedHttp::text('ok')]);
        $response = $this->provider($http, ['k1', 'k2', 'k3'])->complete('S', [], [['role' => 'user', 'content' => 'x']], 100);

        self::assertSame('ok', $response->text);
        self::assertContains('Authorization: Bearer k3', $http->requests[2]['headers']);
    }

    public function testServerErrorsAndExhaustedKeysMeanUnavailable(): void
    {
        try {
            $this->provider(new ScriptedHttp([[503, ['error' => ['message' => 'overloaded']]]]))->complete('S', [], [], 100);
            self::fail('expected LlmUnavailableException');
        } catch (LlmUnavailableException $e) {
            self::assertStringContainsString('503', $e->getMessage());
        }

        $this->expectException(LlmUnavailableException::class);
        $this->provider(new ScriptedHttp([[429, 'x'], [429, 'y']]), ['k1', 'k2'])->complete('S', [], [], 100);
    }

    public function testToolResultsAreToolRoleMessages(): void
    {
        $messages = $this->provider(new ScriptedHttp([]))->toolResultMessages([
            ['id' => 'call_1', 'name' => 'x', 'content' => '{"ok":true}', 'isError' => false],
            ['id' => 'call_2', 'name' => 'y', 'content' => '{"error":"nope"}', 'isError' => true],
        ]);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true}'], $messages[0]);
        self::assertStringStartsWith('ERROR:', $messages[1]['content']);
    }
}
