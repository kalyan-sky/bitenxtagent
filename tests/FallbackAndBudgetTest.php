<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Budget\FileTokenCounter;
use Bitenxt\SupportAgent\Budget\TokenBudget;
use Bitenxt\SupportAgent\Budget\TokenCounter;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Llm\AnthropicProvider;
use Bitenxt\SupportAgent\Llm\OpenAiCompatibleProvider;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;
use PHPUnit\Framework\TestCase;

/** Gemini primary + Claude fallback, and the token limits around every AI call. */
final class FallbackAndBudgetTest extends TestCase
{
    private const TOKEN = 'token-clinic-a';
    private string $dir;
    private ScriptedHttp $gemini;
    private ScriptedClaude $claude;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bnx-llm-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/kb', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array{0?: int, 1?: int, 2?: int} $limits customer/day, global/hour, global/day */
    private function service(array $gemini, array $claude, array $limits = [], array $providerLimits = [], ?TokenCounter $counter = null): ChatService
    {
        $this->gemini = new ScriptedHttp($gemini);
        $this->claude = new ScriptedClaude($claude);
        $logger = new Logger($this->dir . '/log.jsonl');

        return new ChatService(
            sessions: new FileSessionStore($this->dir . '/sessions', 3600),
            rateLimiter: new FileRateLimiter($this->dir . '/rl', 100, 1000),
            inputGuard: new InputGuard(2000),
            outputGuard: new OutputGuard('ref-canary', []),
            agent: new SupportAgent(
                [
                    new OpenAiCompatibleProvider('gemini', 'gemini-test', 'https://gemini.test/v1/', ['g-key'], 'max_tokens', 30, $this->gemini),
                    new AnthropicProvider('claude', 'claude-opus-5-5', [$this->claude]),
                ],
                'system prompt',
                new TokenBudget($counter ?? new FileTokenCounter($this->dir . '/tokens.json'), $logger, $limits[0] ?? 200000, $limits[1] ?? 1000000, $limits[2] ?? 5000000, $providerLimits),
                $logger,
                1024,
                'LIMIT REACHED',
            ),
            magento: new FakeMagento(),
            knowledge: new KnowledgeBase($this->dir . '/kb'),
            handoff: new HandoffNotifier($this->dir . '/handoffs.jsonl'),
            logger: $logger,
            maxTurnsPerConversation: 40,
        );
    }

    private function log(): string
    {
        return (string) @file_get_contents($this->dir . '/log.jsonl');
    }

    public function testGeminiAnswersWithToolsWhenHealthy(): void
    {
        $service = $this->service([
            ScriptedHttp::toolCall('get_order_status', ['order_number' => '000000101']),
            ScriptedHttp::text('Order 000000101 is In design.'),
        ], []);

        $reply = $service->handle('Where is 000000101?', self::TOKEN, '1.1.1.1');

        self::assertSame('Order 000000101 is In design.', $reply['reply']);
        self::assertSame([], $this->claude->requests, 'Claude is not called while Gemini works');
        $toolMessage = $this->gemini->requests[1]['body']['messages'][3];
        self::assertSame('tool', $toolMessage['role']);
        self::assertStringContainsString('In design', $toolMessage['content']);
        self::assertStringNotContainsString('Secret St', json_encode($this->gemini->requests), 'same data guardrails for every provider');
    }

    public function testFallsBackToClaudeWhenGeminiIsDownAndBackWhenItRecovers(): void
    {
        $service = $this->service(
            [[503, ['error' => ['message' => 'overloaded']]], ScriptedHttp::text('Gemini is back.')],
            [ScriptedClaude::text('Claude here.')],
        );

        self::assertSame('Claude here.', $service->handle('Hello', self::TOKEN, '1.1.1.1')['reply']);
        self::assertStringContainsString('"llm_fallback"', $this->log());

        self::assertSame('Gemini is back.', $service->handle('Still there?', self::TOKEN, '1.1.1.1')['reply']);
        $messages = $this->gemini->requests[1]['body']['messages'];
        self::assertSame(
            ['system', 'user', 'assistant', 'user'],
            array_column($messages, 'role'),
            'Gemini picks up from what the customer saw',
        );
        self::assertSame('Claude here.', $messages[2]['content']);
    }

    public function testFallsBackWhenGeminiRefuses(): void
    {
        $refusal = [200, ['choices' => [['finish_reason' => 'content_filter', 'message' => ['role' => 'assistant', 'content' => '']]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 0]]];
        $service = $this->service([$refusal], [ScriptedClaude::text('Here is the crown info.')]);

        self::assertSame('Here is the crown info.', $service->handle('Tell me about zirconia crowns', self::TOKEN, '1.1.1.1')['reply']);
    }

    public function testAllProvidersDown(): void
    {
        // Gemini errors, Claude refuses: nobody can answer.
        $service = $this->service([[500, 'boom']], [['stop_reason' => 'refusal', 'content' => []]]);

        self::assertSame(SupportAgent::FALLBACK_REPLY, $service->handle('Hi', self::TOKEN, '1.1.1.1')['reply']);
        self::assertStringContainsString('"llm_all_failed"', $this->log());
    }

    public function testCustomerDailyLimitStopsBeforeAnyAiCall(): void
    {
        // The first estimate alone exceeds a 500-token daily limit.
        $service = $this->service([ScriptedHttp::text('never')], [], [500]);

        $reply = $service->handle('Hello', self::TOKEN, '1.1.1.1');

        self::assertSame('LIMIT REACHED', $reply['reply']);
        self::assertSame([], $this->gemini->requests);
        self::assertSame([], $this->claude->requests);
        self::assertStringContainsString('"token_budget_exceeded"', $this->log());
        self::assertStringContainsString('"severity":"ERROR"', $this->log(), 'alertable in Cloud Logging');
    }

    public function testServiceWideHourlyLimitStopsEveryone(): void
    {
        $service = $this->service([ScriptedHttp::text('never')], [], [200000, 500]);

        self::assertSame(SupportAgent::UNAVAILABLE_REPLY, $service->handle('Hello', self::TOKEN, '1.1.1.1')['reply']);
        self::assertSame([], $this->gemini->requests);
    }

    public function testProviderDailyLimitFallsBackToTheNextProvider(): void
    {
        $service = $this->service([ScriptedHttp::text('never')], [ScriptedClaude::text('Claude answered.')], [], ['gemini' => 500]);

        self::assertSame('Claude answered.', $service->handle('Hello', self::TOKEN, '1.1.1.1')['reply']);
        self::assertSame([], $this->gemini->requests, 'Gemini is over its own limit and is not called');
    }

    public function testReservationIsCorrectedToRealUsage(): void
    {
        $service = $this->service([ScriptedHttp::text('Hi', 1234, 56)], []);
        $service->handle('Hello', self::TOKEN, '1.1.1.1');

        $counters = json_decode((string) file_get_contents($this->dir . '/tokens.json'), true);
        $globalDay = array_values(array_filter($counters, static fn ($k) => str_starts_with($k, 'global-d'), ARRAY_FILTER_USE_KEY));
        self::assertSame(1290, $globalDay[0]['count'], 'counted exactly what the provider reported (1234 + 56)');
    }

    public function testFailsClosedWhenCountersAreUnreachable(): void
    {
        $broken = new class implements TokenCounter {
            public function add(array $changes): array
            {
                throw new \RuntimeException('Firestore down');
            }
        };
        $service = $this->service([ScriptedHttp::text('never')], [], [], [], $broken);

        self::assertSame(SupportAgent::UNAVAILABLE_REPLY, $service->handle('Hello', self::TOKEN, '1.1.1.1')['reply']);
        self::assertSame([], $this->gemini->requests);
        self::assertSame([], $this->claude->requests);
    }

    public function testOutputCapIsSentToEveryProvider(): void
    {
        $service = $this->service([[503, 'down']], [ScriptedClaude::text('ok')]);
        $service->handle('Hello', self::TOKEN, '1.1.1.1');

        self::assertSame(1024, $this->gemini->requests[0]['body']['max_tokens']);
        self::assertSame(1024, $this->claude->requests[0]['maxTokens']);
    }
}
