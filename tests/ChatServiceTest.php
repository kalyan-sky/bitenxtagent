<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Agent\SupportAgent;
use Bitenxt\SupportAgent\Agent\SupportTools;
use Bitenxt\SupportAgent\Budget\FileTokenCounter;
use Bitenxt\SupportAgent\Budget\TokenBudget;
use Bitenxt\SupportAgent\Chat\ChatService;
use Bitenxt\SupportAgent\Chat\FastPath;
use Bitenxt\SupportAgent\Guardrails\InputGuard;
use Bitenxt\SupportAgent\Guardrails\OutputGuard;
use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Llm\AnthropicProvider;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
{
    private string $dir;
    private FakeMagento $magento;
    private FakeMailer $mailer;
    private FileSessionStore $sessions;

    protected function setUp(): void
    {
        \Bitenxt\SupportAgent\Support\Cache::clear();
        $this->dir = sys_get_temp_dir() . '/bnx-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/kb', 0700, true);
        file_put_contents($this->dir . '/kb/shipping.md', "# Shipping\n\n## Turnaround times\nCrowns take 5 working days.\n");
        $this->magento = new FakeMagento();
        $this->mailer = new FakeMailer();
        $this->sessions = new FileSessionStore($this->dir . '/sessions', 3600);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function service(ScriptedClaude $claude, bool $fastPath = false, int $historyTurns = 6, string $aiMode = 'fallback', bool $articles = false): ChatService
    {
        return new ChatService(
            sessions: $this->sessions,
            rateLimiter: new FileRateLimiter($this->dir . '/rl', 100, 1000),
            inputGuard: new InputGuard(2000),
            outputGuard: new OutputGuard('ref-canary', ['support@bitenxt.com', '+91 96422 03377']),
            agent: new SupportAgent(
                [new AnthropicProvider('claude', 'claude-opus-5-5', [$claude])],
                'system prompt [ref-canary]',
                new TokenBudget(new FileTokenCounter($this->dir . '/tokens.json'), new Logger($this->dir . '/log.jsonl'), 200000, 1000000, 5000000),
                new Logger($this->dir . '/log.jsonl'),
                historyTurns: $historyTurns,
            ),
            magento: $this->magento,
            knowledge: new KnowledgeBase($this->dir . '/kb'),
            handoff: new HandoffNotifier($this->dir . '/handoffs.jsonl', '', $this->mailer, new Logger($this->dir . '/log.jsonl')),
            logger: new Logger($this->dir . '/log.jsonl'),
            maxTurnsPerConversation: 40,
            fastPath: $fastPath ? new FastPath($articles ? new KnowledgeBase(dirname(__DIR__) . '/knowledge') : null, '+91 96422 03377') : null,
            aiMode: $aiMode,
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

    public function testProductSearchWithNoMatchOffersTheCatalogInstead(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('search_products', ['query' => 'Aligners'], 'toolu_a'),
            ScriptedClaude::toolCall('get_catalog_overview', ['category' => ''], 'toolu_b'),
            ScriptedClaude::text('We offer crowns and bridges, for example the Zirconia Crown.'),
        ]);
        $this->service($claude)->handle('Aligners?', 'token-clinic-a', '10.0.0.1');

        [$search, $overview] = self::toolResults($claude);
        self::assertSame([], $search['products']);
        self::assertSame('Crowns & Bridges', $search['categories'][0]['category']);
        self::assertSame(['Zirconia Crown', 'E.max Crown'], $overview['categories'][0]['products']);
    }

    public function testHelpSearchMatchesOtherWordForms(): void
    {
        file_put_contents($this->dir . '/kb/portal.md', "# Portal\n\n## Placing an order\nAdd the item to your cart and check out.\n");
        $kb = new KnowledgeBase($this->dir . '/kb');

        self::assertSame('Placing an order', $kb->search('how to place order?')[0]['title']);
        self::assertSame('Placing an order', $kb->search('placed orders')[0]['title']);
    }

    public function testShippedHelpArticlesAnswerCommonQuestions(): void
    {
        $kb = new KnowledgeBase(dirname(__DIR__) . '/knowledge');

        self::assertSame('How to place an order', $kb->search('how to place order?')[0]['title']);
        self::assertSame('Uploading scan files', $kb->search('upload scan')[0]['title']);
        self::assertSame('Using a coupon', $kb->search('do you have any discount or promo?')[0]['title']);
        self::assertSame('Booking an appointment or consultation', $kb->search('book a demo call')[0]['title']);
        self::assertSame('KIXR scans', $kb->search('kixr validation failed')[0]['title']);
        self::assertSame('Reordering a previous order', $kb->search('order the same thing again')[0]['title']);
    }

    public function testFastPathAnswersOrderStatusWithoutTheAi(): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true)->handle('status of order 000000101?', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertSame([], $claude->requests, 'no AI call, so no tokens spent');
        self::assertStringContainsString('Order 000000101: In design', $reply);
        self::assertStringContainsString('Patient: John S.', $reply);
        self::assertStringContainsString('Zirconia Crown × 2', $reply);
        self::assertStringContainsString('Tracking: UPS 1Z999AA10123456784', $reply);
        self::assertStringNotContainsString('Michael', $reply);
        self::assertStringNotContainsString('Secret St', $reply);
    }

    public function testFastPathKeepsOrdersPrivateAndListsRecentOnes(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);

        $other = $service->handle('000000202', 'token-clinic-a', '10.0.0.1')['reply'];
        self::assertSame("I couldn't find order 000000202 on your account. Please check the number and try again.", $other);

        $list = $service->handle('my orders', 'token-clinic-a', '10.0.0.1')['reply'];
        self::assertStringContainsString('• 000000101 · In design · 20 Sep 2026 · John S.', $list);
        self::assertStringNotContainsString('000000202', $list);
    }

    /** @return iterable<array{string}> */
    public static function messagesForTheAi(): iterable
    {
        yield ['cancel order 000000101'];
        yield ['why is order 000000101 late?'];
        yield ['status of 000000101 and 000000202'];
        yield ['how do I place an order?'];
        yield ['I need 3 crowns'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messagesForTheAi')]
    public function testAnythingNeedingJudgementStillGoesToTheAi(string $message): void
    {
        $claude = new ScriptedClaude([ScriptedClaude::text('Let me help with that.')]);
        $this->service($claude, fastPath: true)->handle($message, 'token-clinic-a', '10.0.0.1');

        self::assertCount(1, $claude->requests);
    }

    public function testAiFollowUpAfterAFastPathReplySeesThatReply(): void
    {
        $claude = new ScriptedClaude([ScriptedClaude::text('It ships with UPS.')]);
        $service = $this->service($claude, fastPath: true);
        $service->handle('track order 000000101', 'token-clinic-a', '10.0.0.1');
        $service->handle('which courier is that with?', 'token-clinic-a', '10.0.0.1');

        $sent = (string) json_encode($claude->requests[0]['messages']);
        self::assertStringContainsString('Order 000000101: In design', $sent);
        self::assertStringContainsString('which courier is that with?', $sent);
    }

    public function testLongChatsOnlySendRecentMessagesToTheAi(): void
    {
        $claude = new ScriptedClaude(array_map(fn ($i) => ScriptedClaude::text("Answer {$i}"), range(1, 4)));
        $service = $this->service($claude, historyTurns: 1);
        foreach (['First question', 'Second question', 'Third question', 'Fourth question'] as $question) {
            $service->handle($question, 'token-clinic-a', '10.0.0.1');
        }

        $last = (string) json_encode(end($claude->requests)['messages']);
        self::assertStringNotContainsString('First question', $last);
        self::assertStringNotContainsString('Second question', $last);
        self::assertStringContainsString('Third question', $last);
        self::assertStringContainsString('Fourth question', $last);
    }

    public function testFastPathCountsOrdersListsCouponsAndShowsTheCart(): void
    {
        $claude = new ScriptedClaude([]);
        $service = $this->service($claude, fastPath: true);

        $count = $service->handle('how many orders do I have?', 'token-clinic-a', '10.0.0.1')['reply'];
        self::assertStringContainsString('You have 1 order in total.', $count);
        self::assertStringContainsString('• Processing: 1', $count);

        $coupons = $service->handle('are there any coupons available for me?', 'token-clinic-a', '10.0.0.1')['reply'];
        self::assertStringContainsString('Code FEST10 · Festive offer · 10% off · valid until 31 Dec 2099', $coupons);
        self::assertStringNotContainsString('OLD5', $coupons, 'expired coupons are hidden');

        $cart = $service->handle("what's in my cart?", 'token-clinic-a', '10.0.0.1')['reply'];
        self::assertStringContainsString('• Zirconia Crown × 2', $cart);
        self::assertStringContainsString('Patient: John S. · Doctor: Dr. Rao', $cart);
        self::assertStringContainsString('Scan upload: Validated (2 files)', $cart);
        self::assertStringNotContainsString('john-smith', $cart, 'file names can hold patient names');
        self::assertStringNotContainsString('52', $cart);

        self::assertSame('Your cart is empty.', $service->handle('my cart', 'token-clinic-b', '10.0.0.2')['reply']);
        self::assertSame([], $claude->requests);
    }

    public function testCouponAndCartToolsGiveTheAiOnlySafeFields(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('get_available_coupons', ['code' => 'fest10'], 'toolu_a'),
            ScriptedClaude::toolCall('get_cart_summary', ['include_items' => false], 'toolu_b'),
            ScriptedClaude::text('FEST10 gives 10% off, and it is already applied to your cart.'),
        ]);
        $this->service($claude)->handle('Can I use FEST10 on my current cart?', 'token-clinic-a', '10.0.0.1');

        [$coupons, $cart] = self::toolResults($claude);
        self::assertSame('FEST10', $coupons['coupons'][0]['code']);
        self::assertArrayNotHasKey('rule_id', $coupons['coupons'][0]);
        self::assertArrayNotHasKey('items', $cart['cart']);
        self::assertSame(['FEST10'], $cart['cart']['coupons_applied']);
        self::assertStringNotContainsString('masked-cart-a', (string) json_encode($cart));
    }

    public function testFeedbackIsLoggedForSignedInCustomersOnly(): void
    {
        $service = $this->service(new ScriptedClaude([ScriptedClaude::text('Hello!')]));
        $at = $service->handle('Hi', 'token-clinic-a', '10.0.0.1')['at'];

        self::assertSame(200, $service->feedback('token-clinic-a', '10.0.0.1', 'down', $at)['status']);
        self::assertSame(400, $service->feedback('token-clinic-a', '10.0.0.1', 'meh', $at)['status']);
        self::assertSame(401, $service->feedback(null, '10.0.0.1', 'up', $at)['status']);
        $log = (string) file_get_contents($this->dir . '/log.jsonl');
        self::assertStringContainsString('"rating":"down"', $log);
    }

    public function testUnexpectedToolErrorsDoNotCrashTheChat(): void
    {
        $this->magento->patientSearchError = new \TypeError('bad row');
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('find_orders_by_patient', ['patient_name' => 'Kalyan'], 'toolu_a'),
            ScriptedClaude::text("Sorry, I can't load those orders right now."),
        ]);
        $reply = $this->service($claude)->handle('orders for patient Kalyan?', 'token-clinic-a', '10.0.0.1');

        self::assertSame(200, $reply['status']);
        self::assertSame('temporarily_unavailable', self::toolResults($claude)[0]['error']);
        $log = (string) file_get_contents($this->dir . '/log.jsonl');
        self::assertStringContainsString('"tool_exception"', $log);
        self::assertStringContainsString('TypeError', $log);
    }

    public function testPatientListUsesOnlyOwnOrdersAndShortLabels(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('list_patients', ['name' => ''], 'toolu_a'),
            ScriptedClaude::text('Your patients: John S. (1 order, latest 000000101).'),
        ]);
        $this->service($claude)->handle('list my patients', 'token-clinic-a', '10.0.0.1');

        $result = self::toolResults($claude)[0];
        self::assertSame([['patient' => 'John S.', 'orders' => 1, 'latest_order' => '000000101', 'latest_date' => '2026-09-20 10:00:00']], $result['patients']);
        self::assertStringNotContainsString('Mary', (string) json_encode($result), 'other clinics\' patients never appear');
        self::assertStringNotContainsString('Michael', (string) json_encode($result));
    }

    public function testPatientListComesFromTheClinicsOwnPatients(): void
    {
        $this->magento->patientLists['11'] = [['id' => '5', 'name' => 'Kalyan Kumar'], ['id' => '6', 'name' => 'Narendra']];
        $this->magento->patientLists['22'] = [['id' => '9', 'name' => 'Other Clinic Patient']];
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('list_patients', ['name' => ''], 'toolu_a'),
            ScriptedClaude::text('Your patients are Kalyan K. and Narendra.'),
        ]);
        $this->service($claude)->handle('list my patients', 'token-clinic-a', '10.0.0.1');

        $result = self::toolResults($claude)[0];
        self::assertSame([['patient' => 'Kalyan K.'], ['patient' => 'Narendra']], $result['patients']);
        self::assertStringNotContainsString('Other Clinic', (string) json_encode($result));
    }

    public function testKnownPatientWithoutMatchableOrdersIsNotCalledMissing(): void
    {
        $this->magento->patientLists['11'] = [['id' => '5', 'name' => 'Kalyan']];
        $claude = new ScriptedClaude([
            ScriptedClaude::toolCall('find_orders_by_patient', ['patient_name' => 'kalyan'], 'toolu_a'),
            ScriptedClaude::text('Kalyan is in your patient list, but I can\'t match their orders here yet.'),
        ]);
        $this->service($claude)->handle('orders for patient kalyan', 'token-clinic-a', '10.0.0.1');

        $result = self::toolResults($claude)[0];
        self::assertSame(0, $result['count']);
        self::assertTrue($result['patient_in_patient_list']);
    }

    /** @return iterable<string, array{string, string}> message => text the instant reply must contain */
    public static function instantAnswers(): iterable
    {
        yield 'greeting' => ['hi', 'Tap a topic'];
        yield 'thanks' => ['thank you!', "You're welcome"];
        yield 'support' => ['I want to talk to support', 'What do you need help with?'];
        yield 'support with details' => ['connect me to customer care, my crown is cracked', 'passed your request to our support team'];
        yield 'track without number' => ['track my order', 'Send me the order number'];
        yield 'patient orders' => ['orders for patient john', "Orders for John S. (newest first):\n• 000000101"];
        yield 'patient orders 2' => ['what are orders related to john patient?', '000000101'];
        yield 'latest for patient' => ['latest order for John', 'Order 000000101: In design'];
        yield 'patient order count' => ['how many orders does John have?', 'John S. has 1 order.'];
        yield 'patient list' => ['who are my patients?', "Your patients:\n• John S."];
        yield 'follow-ups' => ['follow ups on order 000000101', 'Please adjust the margin.'];
        yield 'catalog' => ['what products are available?', 'Crowns & Bridges: Zirconia Crown, E.max Crown'];
        yield 'product search' => ['do you have zirconia crowns?', 'Products matching "zirconia crowns"'];
        yield 'product missing' => ['do you have aligners?', 'I couldn\'t find "aligners" in the catalog'];
        yield 'short product' => ['Crown?', 'Zirconia Crown'];
        yield 'how-to article' => ['How do I place an order?', "How to place an order\n1. Sign in"];
        yield 'kixr article' => ['how do I upload a KIXR scan?', 'KIXR scans'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('instantAnswers')]
    public function testCommonQuestionsAreAnsweredWithoutTheAi(string $message, string $expected): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true, articles: true)->handle($message, 'token-clinic-a', '10.0.0.1');

        self::assertSame([], $claude->requests, 'no AI call');
        self::assertStringContainsString($expected, $reply['reply']);
        self::assertNotEmpty($reply['quick_replies'] ?? [], 'every instant answer offers next steps');
        self::assertStringNotContainsString('Michael', $reply['reply'], 'patients are only ever shown as First L.');
    }

    public function testBareSupportRequestAsksWhatItIsAboutThenSendsIt(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);
        $ask = $service->handle('talk to support', 'token-clinic-a', '10.0.0.1');

        self::assertStringContainsString('What do you need help with?', $ask['reply']);
        self::assertSame(['Just connect me', 'Cancel'], $ask['quick_replies']);
        self::assertSame([], $this->mailer->sent, 'nothing is sent until we know what it is about');

        $reply = $service->handle('my aligner trays are not fitting', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString("I've passed your request to our support team. They'll reply to your registered email (ana@clinic-a.test).", $reply);
        self::assertStringContainsString("If it's urgent, call us on +91 96422 03377.", $reply);
        self::assertCount(1, $this->mailer->sent);
        self::assertStringContainsString('"my aligner trays are not fitting"', $this->mailer->sent[0]['subject']);
        self::assertStringContainsString("WHAT THE CUSTOMER NEEDS\n  my aligner trays are not fitting", $this->mailer->sent[0]['body']);
        $handoff = json_decode(trim((string) file_get_contents($this->dir . '/handoffs.jsonl')), true);
        self::assertSame('customer_requested', $handoff['reason']);
    }

    /** @return iterable<array{string}> */
    public static function waysToAskForSupport(): iterable
    {
        yield ['talk to support'];
        yield ['Support'];
        yield ['customer care'];
        yield ['I need a human'];
        yield ['can I speak to someone?'];
        yield ['connect me with your team please'];
        yield ['please call me back'];
        yield ['I want to raise a complaint'];
        yield ['contact support'];
        yield ['need help'];
        yield ['email the support team'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('waysToAskForSupport')]
    public function testEveryWayOfAskingForSupportIsRecognised(string $message): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true)->handle($message, 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertSame([], $claude->requests, 'no AI call');
        self::assertStringContainsString("I'll connect you with our support team", $reply);
    }

    public function testJustConnectMeAndCancel(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);
        $service->handle('talk to support', 'token-clinic-a', '10.0.0.1');
        $cancelled = $service->handle('Cancel', 'token-clinic-a', '10.0.0.1')['reply'];
        $service->handle('I need a human', 'token-clinic-a', '10.0.0.1');
        $sent = $service->handle('Just connect me', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString("I won't contact the team", $cancelled);
        self::assertStringContainsString("I've passed your request to our support team", $sent);
        self::assertCount(1, $this->mailer->sent);
    }

    /** @return iterable<array{string}> */
    public static function questionsForTheAi(): iterable
    {
        yield ['cancel order 000000101'];
        yield ['my crown for John is late, why?'];
        yield ['what is the turnaround time for crowns?'];
        yield ['can I change the shade on order 000000101?'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('questionsForTheAi')]
    public function testJudgementCallsStillGoToTheAiInFallbackMode(string $message): void
    {
        $claude = new ScriptedClaude([ScriptedClaude::text('Let me help with that.')]);
        $this->service($claude, fastPath: true, articles: true)->handle($message, 'token-clinic-a', '10.0.0.1');

        self::assertCount(1, $claude->requests);
    }

    public function testWithTheAiOffUnmatchedQuestionsGetTheMenu(): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true, aiMode: 'off', articles: true)
            ->handle('what is the turnaround time for crowns?', 'token-clinic-a', '10.0.0.1');

        self::assertSame([], $claude->requests);
        self::assertSame(ChatService::MENU_REPLY, $reply['reply']);
        self::assertSame(FastPath::MENU, $reply['quick_replies']);
    }

    public function testOtherClinicsPatientsAreNotFound(): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true)->handle('orders for patient Mary', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertSame("I couldn't find any orders for a patient named \"Mary\" on your account.", $reply);
    }

    public function testHandOverEmailsTheSupportInboxWithTheConversation(): void
    {
        $claude = new ScriptedClaude([
            ScriptedClaude::text('Order 000000101 is in design.'),
            ScriptedClaude::toolCall('escalate_to_human', [
                'reason' => 'order_change', 'summary' => 'Wants a different shade.', 'order_number' => '000000101', 'urgency' => 'high',
            ], 'toolu_a'),
            ScriptedClaude::text('I have passed this to our team.'),
        ]);
        $service = $this->service($claude);
        $service->handle('status of order 000000101?', 'token-clinic-a', '10.0.0.1');
        $service->handle('Please change the shade on that order', 'token-clinic-a', '10.0.0.1');

        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertSame('[BiteNXT Support] URGENT | Order change: "Please change the shade on that order" | Ana (ana@clinic-a.test)', $mail['subject']);
        self::assertSame('ana@clinic-a.test', $mail['replyTo'], 'the team can reply to the customer directly');

        // Plain-text part: labelled sections.
        self::assertStringContainsString("REQUEST\n  Reason      : Order change\n  Urgency     : URGENT", $mail['body']);
        self::assertStringContainsString('  Summary     : Wants a different shade.', $mail['body']);
        self::assertStringContainsString("CUSTOMER\n  Name        : Ana\n  Email       : ana@clinic-a.test", $mail['body']);
        self::assertMatchesRegularExpression('/Received    : \d{2} \w{3} \d{4}, \d{2}:\d{2} [AP]M IST/', $mail['body']);
        self::assertStringContainsString("WHAT THE CUSTOMER NEEDS\n  Please change the shade on that order\n\nREQUEST", $mail['body']);
        self::assertMatchesRegularExpression('/RECENT CONVERSATION \(newest first\)\n-+\n\[Customer\][^\n]*\n  Please change the shade on that order\n\n'
            . '\[Chatbot\][^\n]*\n  Order 000000101 is in design\.\n\n\[Customer\][^\n]*\n  status of order 000000101\?/', $mail['body']);

        // HTML part: the same details, laid out for email clients.
        self::assertStringContainsString('New support request from chat', $mail['html']);
        self::assertStringContainsString('<a href="mailto:ana@clinic-a.test"', $mail['html']);
        self::assertStringContainsString('Wants a different shade.', $mail['html']);
        self::assertStringContainsString('Please change the shade on that order', $mail['html']);
    }

    public function testHandOverEmailEscapesChatText(): void
    {
        $mailer = new FakeMailer();
        $notifier = new \Bitenxt\SupportAgent\Support\HandoffNotifier($this->dir . '/h.jsonl', '', $mailer);
        $notifier->notify([
            'session_id' => 's1', 'customer_id' => 7, 'customer_name' => 'Ana', 'customer_email' => 'ana@clinic-a.test',
            'reason' => 'other', 'urgency' => 'normal', 'order_number' => '', 'summary' => 'Needs <b>help</b>',
            'conversation' => [['role' => 'user', 'text' => "<script>alert(1)</script>\nline two"]],
        ]);

        $html = $mailer->sent[0]['html'];
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;<br>', $html);
        self::assertStringContainsString('Needs &lt;b&gt;help&lt;/b&gt;', $html);
        self::assertSame('[BiteNXT Support] Other | Ana (ana@clinic-a.test)', $mailer->sent[0]['subject']);
    }

    /** @return iterable<array{string}> */
    public static function attachmentOrNoteChanges(): iterable
    {
        yield ['i need to update the order attachments and related notes'];
        yield ['update attachments for order 000000101'];
        yield ['I want to replace the STL file'];
        yield ['how do I add notes to my order?'];
        yield ['need to change the order details'];
        yield ['can I change the shade on order 000000101 and add a note?'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attachmentOrNoteChanges')]
    public function testAttachmentAndNoteChangesExplainMyOrderAndOfferEmail(string $message): void
    {
        $claude = new ScriptedClaude([]);
        $reply = $this->service($claude, fastPath: true, articles: true)->handle($message, 'token-clinic-a', '10.0.0.1');

        self::assertSame([], $claude->requests, 'no AI call');
        self::assertStringContainsString('1. Open My Order in BiteNXT Pro.', $reply['reply']);
        self::assertStringContainsString('I can email your request to our support team.', $reply['reply']);
        self::assertSame('Email this to support', $reply['quick_replies'][0]);
        self::assertSame([], $this->mailer->sent, 'nothing is emailed until the customer asks');
    }

    public function testEmailThisToSupportSendsTheOriginalRequest(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);
        $offer = $service->handle('I need to update the attachments on order 101, the lower scan was wrong', 'token-clinic-a', '10.0.0.1');
        $sent = $service->handle('Email this to support', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString('order 000000101 yourself', $offer['reply']);
        self::assertSame(['Email this to support', 'Follow-ups on order 101'], $offer['quick_replies']);
        self::assertStringContainsString("I've passed your request about order 000000101 to our support team. They'll reply to your registered email", $sent);
        self::assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        self::assertStringContainsString('Order change: "I need to update the attachments on order 101', $mail['subject']);
        self::assertStringContainsString('| Order 000000101 |', $mail['subject']);
        self::assertStringContainsString("WHAT THE CUSTOMER NEEDS\n  I need to update the attachments on order 101, the lower scan was wrong", $mail['body']);
    }

    public function testEmailThisToSupportWithoutAnOfferAsksWhatItIsAbout(): void
    {
        $reply = $this->service(new ScriptedClaude([]), fastPath: true)->handle('Email this to support', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString('What do you need help with?', $reply);
        self::assertSame([], $this->mailer->sent);
    }

    public function testFailedHandOverIsNotPromisedToTheCustomer(): void
    {
        $this->mailer->fail = true;
        $reply = $this->service(new ScriptedClaude([]), fastPath: true)->handle('talk to support, my scan upload keeps failing', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString("Sorry, I couldn't reach the team from chat just now. Please call +91 96422 03377", $reply);
        self::assertStringNotContainsString('registered email', $reply, 'no promise when nothing was sent');
        self::assertStringContainsString('handoff_not_delivered', (string) file_get_contents($this->dir . '/log.jsonl') . $this->handoffLog());
    }

    private function handoffLog(): string
    {
        return (string) @file_get_contents($this->dir . '/handoffs.jsonl');
    }

    public function testEachDistinctSupportRequestIsEmailedButRepeatsAreNot(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);
        $say = fn (string $text) => $service->handle($text, 'token-clinic-a', '10.0.0.1')['reply'];

        $aboutOrder = $say('email the support now regarding order number 000000101');
        $say('talk to support');
        $general = $say('I want to update my clinic address');
        $say('talk to support');
        $another = $say('my invoice shows the wrong GST number');
        $repeat = $say('talk to support, my invoice shows the wrong GST number');
        $say('talk to support');
        $bare = $say('Just connect me');

        self::assertStringContainsString("I've passed your request about order 000000101 to our support team", $aboutOrder);
        self::assertStringContainsString("I've passed your request to our support team", $general, 'a plain request after an order request is not "already requested"');
        self::assertStringContainsString("I've passed your request to our support team", $another);
        self::assertStringContainsString("I've passed your request to our support team", $repeat, 'different wording counts as a new request');
        self::assertStringContainsString("I've passed your request to our support team", $bare);
        self::assertCount(5, $this->mailer->sent);
        self::assertStringContainsString('| Order 000000101 |', $this->mailer->sent[0]['subject']);
        self::assertStringNotContainsString('Order 000000101', $this->mailer->sent[1]['subject'], 'not tied to the earlier order');

        // Sending exactly the same request again is held back.
        $say('talk to support');
        self::assertStringContainsString("You've already sent this request", $say('Just connect me'));
    }

    public function testSupportAboutSomeoneElsesOrderIsNotSent(): void
    {
        $reply = $this->service(new ScriptedClaude([]), fastPath: true)
            ->handle('talk to support about order 000000202', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertStringContainsString("I couldn't find order 000000202 on your account", $reply);
        self::assertSame([], $this->mailer->sent);
    }

    public function testHandOversAreCappedPerConversation(): void
    {
        $claude = new ScriptedClaude(array_merge(...array_map(fn ($i) => [
            ScriptedClaude::toolCall('escalate_to_human', ['reason' => ['order_change', 'refund_or_billing', 'remake_or_quality', 'delivery_problem',
                'technical_issue', 'other'][$i], 'summary' => 'Issue ' . $i, 'order_number' => '', 'urgency' => 'normal'], 'toolu_' . $i),
            ScriptedClaude::text('Passed on.'),
        ], range(0, 5))));
        $service = $this->service($claude);
        foreach (range(0, 5) as $i) {
            $service->handle('I have another problem ' . $i, 'token-clinic-a', '10.0.0.1');
        }

        self::assertCount(SupportTools::MAX_HANDOFFS_PER_CONVERSATION, $this->mailer->sent);
    }

    public function testTalkToSupportAfterAnOrderOffersThatOrder(): void
    {
        $service = $this->service(new ScriptedClaude([]), fastPath: true);
        $service->handle('status of order 000000101', 'token-clinic-a', '10.0.0.1');
        $ask = $service->handle('talk to support', 'token-clinic-a', '10.0.0.1');
        $reply = $service->handle('About order 101', 'token-clinic-a', '10.0.0.1')['reply'];

        self::assertSame(['About order 101', 'Just connect me', 'Cancel'], $ask['quick_replies']);
        self::assertStringContainsString("I've passed your request about order 000000101", $reply);
        self::assertCount(1, $this->mailer->sent);
        self::assertStringContainsString('| Order 000000101 |', $this->mailer->sent[0]['subject']);
    }

    public function testOneBadInboxDoesNotStopTheOthers(): void
    {
        $log = $this->dir . '/mail.jsonl';
        $mailer = new class ($log) extends \Bitenxt\SupportAgent\Support\HandoffMailer {
            public array $accepted = [];

            public function __construct(string $log)
            {
                parent::__construct('smtp.test', 587, 'bot@x.test', 'pw', 'bot@x.test',
                    ['typo@gmail.com', 'contact@bitenxt.com'], 'tls', new Logger($log));
            }

            protected function sendOne(string $to, string $subject, string $body, string $replyTo, string $html = ''): ?string
            {
                if ($to === 'typo@gmail.com') {
                    return 'SMTP Error: The following recipients failed: typo@gmail.com';
                }
                $this->accepted[] = $to;

                return null;
            }
        };

        self::assertTrue($mailer->send('Subject', 'Body', 'ana@clinic-a.test'));
        self::assertSame(['contact@bitenxt.com'], $mailer->accepted);
        self::assertStringContainsString('"to":"typo@gmail.com"', (string) file_get_contents($log));
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
