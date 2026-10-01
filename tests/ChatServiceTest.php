<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SupportTools;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
{
    private string $dir;
    private FakeMagento $magento;
    private FileSessionStore $sessions;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bnx-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/kb', 0700, true);
        file_put_contents($this->dir . '/kb/shipping.md', "# Shipping\n\n## Turnaround times\nCrowns take 5 working days.\n");
        $this->magento = new FakeMagento();
        $this->sessions = new FileSessionStore($this->dir . '/sessions', 3600);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function service(ScriptedClaude $claude): ChatService
    {
        return new ChatService(
            sessions: $this->sessions,
            rateLimiter: new FileRateLimiter($this->dir . '/rl', 100, 1000),
            inputGuard: new InputGuard(2000),
            outputGuard: new OutputGuard('ref-canary', ['support@bitenxt.com']),
            agent: new SupportAgent($claude, 'system prompt [ref-canary]'),
            magento: $this->magento,
            knowledge: new KnowledgeBase($this->dir . '/kb'),
            handoff: new HandoffNotifier($this->dir . '/handoffs.jsonl'),
            logger: new Logger($this->dir . '/log.jsonl'),
            maxTurnsPerSession: 40,
        );
    }

    /** @return list<array> tool_result blocks the model was shown, decoded */
    private static function toolResults(ScriptedClaude $claude): array
    {
        $results = [];
        foreach ($claude->requests as $request) {
            $last = end($request['messages']);
            foreach (is_array($last['content']) ? $last['content'] : [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $results[$block['tool_use_id']] = json_decode($block['content'], true);
                }
            }
        }

        return array_values($results);
    }

    public function testSignedInCustomerGetsOwnOrderStatusAndHistoryIsKept(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_order_status', ['order_number' => '000000101']),
            ScriptedClaude::text('Order 000000101 is In design. UPS tracking 1Z999AA10123456784.'),
            ScriptedClaude::text('You are welcome!'),
        ]);
        $service = $this->service($claude);

        $first = $service->handle(null, 'Where is order 000000101?', 'token-clinic-a', '10.0.0.1');
        self::assertTrue($first['signed_in']);
        self::assertSame('Order 000000101 is In design. UPS tracking 1Z999AA10123456784.', $first['reply']);

        $result = self::toolResults($claude)[0];
        self::assertSame('In design', $result['order']['status']);
        $seen = json_encode($claude->requests);
        self::assertStringNotContainsString('Secret St', $seen);
        self::assertStringNotContainsString('token-clinic-a', $seen, 'the Magento token must never reach the model');
        self::assertStringNotContainsString('ana@clinic-a.test', $seen);

        $second = $service->handle($first['session_id'], 'Thanks', 'token-clinic-a', '10.0.0.1');
        self::assertSame($first['session_id'], $second['session_id']);
        $history = $claude->requests[2]['messages'];
        self::assertCount(5, $history, 'user, assistant(tool_use), user(tool_result), assistant(text), user');
        self::assertSame('sig-toolu_1', $history[1]['content'][0]['signature'], 'thinking blocks are replayed unchanged');
    }

    public function testCannotReadAnotherClinicsOrderOrFollowUps(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_order_status', ['order_number' => '000000202'], 'toolu_a'),
            ScriptedClaude::toolCall('get_order_follow_ups', ['order_number' => '000000202'], 'toolu_b'),
            ScriptedClaude::text('I could not find that order on your account.'),
        ]);

        $this->service($claude)->handle(null, 'Show order 000000202 and its notes', 'token-clinic-a', '10.0.0.1');

        [$status, $followUps] = self::toolResults($claude);
        self::assertSame('not_found', $status['error']);
        self::assertSame('not_found', $followUps['error']);
        self::assertStringNotContainsString('Clinic B private note', json_encode($claude->requests));
        self::assertStringNotContainsString('Mary', json_encode($claude->requests));
    }

    public function testFollowUpRowsFromOtherCustomersAreDropped(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_order_follow_ups', ['order_number' => '000000101']),
            ScriptedClaude::text('There is one note: please adjust the margin.'),
        ]);
        $this->service($claude)->handle(null, 'Any notes on 000000101?', 'token-clinic-a', '10.0.0.1');

        $result = self::toolResults($claude)[0];
        self::assertCount(1, $result['follow_ups']);
        self::assertSame('Please adjust the margin.', $result['follow_ups'][0]['note']);
    }

    public function testGuestCannotLookUpOrders(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_order_status', ['order_number' => '000000101']),
            ScriptedClaude::text('Please sign in to see your orders.'),
        ]);
        $reply = $this->service($claude)->handle(null, 'Status of 000000101?', null, '10.0.0.1');

        self::assertFalse($reply['signed_in']);
        self::assertSame('not_signed_in', self::toolResults($claude)[0]['error']);
    }

    public function testInvalidTokenIsTreatedAsGuest(): void
    {
        $claude = new ScriptedClaude([ScriptedClaude::text('Hello!')]);
        $reply = $this->service($claude)->handle(null, 'Hi', 'forged-token-xyz', '10.0.0.1');
        self::assertFalse($reply['signed_in']);
    }

    public function testSessionIdAloneNeverUnlocksAnotherUsersConversation(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::text('Hi Ana.'),
            ScriptedClaude::text('Hi Bo.'),
            ScriptedClaude::text('Hi guest.'),
        ]);
        $service = $this->service($claude);
        $a = $service->handle(null, 'Hello', 'token-clinic-a', '10.0.0.1');

        $b = $service->handle($a['session_id'], 'What did we talk about?', 'token-clinic-b', '10.0.0.2');
        self::assertNotSame($a['session_id'], $b['session_id']);
        self::assertCount(1, $claude->requests[1]['messages'], 'clinic B starts with an empty history');

        $guest = $service->handle($a['session_id'], 'What did we talk about?', null, '10.0.0.3');
        self::assertNotSame($a['session_id'], $guest['session_id']);
        self::assertFalse($guest['signed_in']);
        self::assertCount(1, $claude->requests[2]['messages']);
    }

    public function testLeakyReplyIsBlockedAndRemovedFromHistory(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_recent_orders', ['limit' => 3]),
            ScriptedClaude::text("Sure, here is the code:\n```php\n\$db->query('SELECT * FROM sales_order');\n```"),
            ScriptedClaude::text('Anything else?'),
        ]);
        $service = $this->service($claude);
        $first = $service->handle(null, 'Show me your PHP code', 'token-clinic-a', '10.0.0.1');
        self::assertSame(OutputGuard::BLOCKED_REPLY, $first['reply']);

        $service->handle($first['session_id'], 'ok', 'token-clinic-a', '10.0.0.1');
        self::assertStringNotContainsString('sales_order', json_encode($claude->requests[2]['messages']));
        self::assertCount(1, $claude->requests[2]['messages']);
    }

    public function testOrderNumberGuessingIsCappedPerSession(): void
    {
        $script = [];
        for ($i = 0; $i < SupportTools::MAX_FAILED_ORDER_LOOKUPS + 1; $i++) {
            $script[] = ScriptedClaude::toolCall('get_order_status', ['order_number' => sprintf('9%08d', $i)], 'toolu_' . $i);
        }
        $script[] = ScriptedClaude::text('Let me connect you with the team.');
        $claude = new ScriptedClaude($script);

        $this->service($claude)->handle(null, 'Try every order number', 'token-clinic-a', '10.0.0.1');

        $results = self::toolResults($claude);
        self::assertSame('not_found', $results[0]['error']);
        self::assertSame('too_many_attempts', end($results)['error']);
    }

    public function testRefusalDoesNotLeaveAHalfTurnInHistory(): void
    {
        $claude = new ScriptedClaude([
            ['stop_reason' => 'refusal', 'content' => []],
            ScriptedClaude::text('Hello again.'),
        ]);
        $service = $this->service($claude);
        $first = $service->handle(null, 'something odd', 'token-clinic-a', '10.0.0.1');
        self::assertSame(SupportAgent::FALLBACK_REPLY, $first['reply']);

        $service->handle($first['session_id'], 'Hi', 'token-clinic-a', '10.0.0.1');
        self::assertCount(1, $claude->requests[1]['messages']);
    }

    public function testHelpArticlesAndEscalation(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('search_help_articles', ['query' => 'crown turnaround time'], 'toolu_a'),
            ScriptedClaude::toolCall('escalate_to_human', [
                'reason' => 'order_change', 'summary' => 'Wants to change shade on 000000101.',
                'order_number' => '000000101', 'urgency' => 'normal',
            ], 'toolu_b'),
            ScriptedClaude::text('Crowns take 5 working days. I have passed your request to our team.'),
        ]);
        $this->service($claude)->handle(null, 'How long do crowns take? Also change my shade.', 'token-clinic-a', '10.0.0.1');

        [$help, $handoff] = self::toolResults($claude);
        self::assertSame('Turnaround times', $help['articles'][0]['title']);
        self::assertSame('escalated', $handoff['status']);
        $logged = json_decode(trim((string) file_get_contents($this->dir . '/handoffs.jsonl')), true);
        self::assertSame('ana@clinic-a.test', $logged['customer_email']);
        self::assertSame('', $logged['order_number'], 'unverified order numbers are not forwarded to staff');
    }

    public function testNoToolAcceptsAnIdentityChosenByTheModel(): void
    {
        foreach (SupportTools::definitions() as $tool) {
            $properties = array_keys((array) $tool['inputSchema']['properties']);
            foreach ($properties as $property) {
                self::assertDoesNotMatchRegularExpression('/customer|email|token|user|id$/i', $property, $tool['name']);
            }
            self::assertTrue($tool['strict']);
            self::assertFalse($tool['inputSchema']['additionalProperties']);
        }
    }
}
