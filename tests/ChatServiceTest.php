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
            maxTurnsPerConversation: 40,
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

        $first = $service->handle('Where is order 000000101?', 'token-clinic-a', '10.0.0.1');
        self::assertSame(200, $first['status']);
        self::assertSame('Order 000000101 is In design. UPS tracking 1Z999AA10123456784.', $first['reply']);

        $result = self::toolResults($claude)[0];
        self::assertSame('In design', $result['order']['status']);
        $seen = json_encode($claude->requests);
        self::assertStringNotContainsString('Secret St', $seen);
        self::assertStringNotContainsString('token-clinic-a', $seen, 'the Magento token must never reach the model');
        self::assertStringNotContainsString('ana@clinic-a.test', $seen);

        $service->handle('Thanks', 'token-clinic-a', '10.0.0.1');
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

        $this->service($claude)->handle('Show order 000000202 and its notes', 'token-clinic-a', '10.0.0.1');

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
        $this->service($claude)->handle('Any notes on 000000101?', 'token-clinic-a', '10.0.0.1');

        $result = self::toolResults($claude)[0];
        self::assertCount(1, $result['follow_ups']);
        self::assertSame('Please adjust the margin.', $result['follow_ups'][0]['note']);
    }

    public function testChatIsRefusedWithoutAValidLogin(): void
    {
        $claude = new ScriptedClaude([]);
        $service = $this->service($claude);

        foreach ([null, '', 'forged-token-xyz'] as $token) {
            $reply = $service->handle('Status of 000000101?', $token, '10.0.0.1');
            self::assertSame(401, $reply['status']);
            self::assertSame('login_required', $reply['error']);
            self::assertSame(401, $service->history($token, '10.0.0.1')['status']);
        }
        self::assertSame([], $claude->requests, 'Claude is never called for logged-out users');
        self::assertSame([], glob($this->dir . '/sessions/*.json'), 'nothing is stored');
    }

    public function testChatIsRefusedWhenTheLoginCannotBeVerified(): void
    {
        $this->magento->down = true;
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude)->handle('Hi', 'token-clinic-a', '10.0.0.1');

        self::assertSame(503, $reply['status']);
        self::assertSame([], $claude->requests);
    }

    public function testEachCustomerHasTheirOwnThread(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::text('Hi Ana.'),
            ScriptedClaude::text('Hi Bo.'),
            ScriptedClaude::text('Hi Ana again.'),
        ]);
        $service = $this->service($claude);
        $service->handle('Hello from A', 'token-clinic-a', '10.0.0.1');

        $service->handle('What did we talk about?', 'token-clinic-b', '10.0.0.2');
        self::assertCount(1, $claude->requests[1]['messages'], 'clinic B starts with an empty conversation');

        $service->handle('Hi again', 'token-clinic-a', '10.0.0.1');
        self::assertCount(3, $claude->requests[2]['messages'], 'clinic A continues its own conversation');

        $threadA = array_column($service->history('token-clinic-a', '10.0.0.1')['messages'], 'text');
        $threadB = array_column($service->history('token-clinic-b', '10.0.0.2')['messages'], 'text');
        self::assertSame(['Hello from A', 'Hi Ana.', 'Hi again', 'Hi Ana again.'], $threadA);
        self::assertSame(['What did we talk about?', 'Hi Bo.'], $threadB);
    }

    public function testSameCustomerWithANewTokenKeepsTheThread(): void
    {
        $this->magento->accounts['token-clinic-a-renewed'] = $this->magento->accounts['token-clinic-a'];
        $claude = new ScriptedClaude([ScriptedClaude::text('Hi.'), ScriptedClaude::text('Still here.')]);
        $service = $this->service($claude);

        $service->handle('Hello', 'token-clinic-a', '10.0.0.1');
        $service->handle('Back again', 'token-clinic-a-renewed', '10.0.0.1');

        self::assertCount(3, $claude->requests[1]['messages']);
        self::assertCount(4, $service->history('token-clinic-a-renewed', '10.0.0.1')['messages']);
    }

    public function testAfterAQuietSpellClaudeStartsFreshButTheThreadKeepsEverything(): void
    {
        $claude = new ScriptedClaude([ScriptedClaude::text('Old answer.'), ScriptedClaude::text('New answer.')]);
        $service = $this->service($claude);
        $service->handle('Old question', 'token-clinic-a', '10.0.0.1');

        // Pretend the last message was two hours ago.
        $files = glob($this->dir . '/sessions/*.json');
        $data = json_decode(file_get_contents($files[0]), true);
        $data['updatedAt'] = time() - 7200;
        foreach ($data['transcript'] as &$m) {
            $m['at'] -= 7200;
        }
        file_put_contents($files[0], json_encode($data));

        $service->handle('New question', 'token-clinic-a', '10.0.0.1');

        self::assertCount(1, $claude->requests[1]['messages'], 'Claude does not see the old conversation');
        self::assertCount(2, glob($this->dir . '/sessions/*.json'));
        self::assertSame(
            ['Old question', 'Old answer.', 'New question', 'New answer.'],
            array_column($service->history('token-clinic-a', '10.0.0.1')['messages'], 'text'),
            'the customer still sees one continuous thread, oldest first',
        );
    }

    public function testHistoryShowsExactlyWhatTheCustomerSaw(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::text('Contact bo@clinic-b.test for that.'),
            ScriptedClaude::text("```php\necho 1;\n```"),
        ]);
        $service = $this->service($claude);
        $service->handle('Who handles that?', 'token-clinic-a', '10.0.0.1');
        $service->handle('Show me code', 'token-clinic-a', '10.0.0.1');

        $thread = json_encode($service->history('token-clinic-a', '10.0.0.1'));
        self::assertStringNotContainsString('bo@clinic-b.test', $thread, 'redactions stay redacted');
        self::assertStringContainsString('[email hidden]', $thread);
        self::assertStringNotContainsString('echo 1', $thread, 'blocked replies never come back');
        self::assertStringContainsString(json_encode(OutputGuard::BLOCKED_REPLY), $thread);
    }

    public function testLeakyReplyIsBlockedAndRemovedFromHistory(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_recent_orders', ['limit' => 3]),
            ScriptedClaude::text("Sure, here is the code:\n```php\n\$db->query('SELECT * FROM sales_order');\n```"),
            ScriptedClaude::text('Anything else?'),
        ]);
        $service = $this->service($claude);
        $first = $service->handle('Show me your PHP code', 'token-clinic-a', '10.0.0.1');
        self::assertSame(OutputGuard::BLOCKED_REPLY, $first['reply']);
        self::assertSame(200, $first['status']);

        $service->handle('ok', 'token-clinic-a', '10.0.0.1');
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

        $this->service($claude)->handle('Try every order number', 'token-clinic-a', '10.0.0.1');

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
        $first = $service->handle('something odd', 'token-clinic-a', '10.0.0.1');
        self::assertSame(SupportAgent::FALLBACK_REPLY, $first['reply']);

        $service->handle('Hi', 'token-clinic-a', '10.0.0.1');
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
        $this->service($claude)->handle('How long do crowns take? Also change my shade.', 'token-clinic-a', '10.0.0.1');

        [$help, $handoff] = self::toolResults($claude);
        self::assertSame('Turnaround times', $help['articles'][0]['title']);
        self::assertSame('escalated', $handoff['status']);
        $logged = json_decode(trim((string) file_get_contents($this->dir . '/handoffs.jsonl')), true);
        self::assertSame('ana@clinic-a.test', $logged['customer_email']);
        self::assertSame('', $logged['order_number'], 'unverified order numbers are not forwarded to the team');
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
