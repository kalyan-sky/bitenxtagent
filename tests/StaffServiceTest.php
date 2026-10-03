<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Guardrails\FileRateLimiter;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Session\FileSessionStore;
use Bitenxt\SupportAgent\Staff\StaffService;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;
use PHPUnit\Framework\TestCase;

final class StaffServiceTest extends TestCase
{
    private const KEY = 'staff-key-0123456789abcdef0123456789';
    private string $dir;
    private FileSessionStore $sessions;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bnx-staff-' . bin2hex(random_bytes(4));
        $this->sessions = new FileSessionStore($this->dir . '/sessions', 3600);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function staff(string $key = self::KEY): StaffService
    {
        return new StaffService($this->sessions, new FileRateLimiter($this->dir . '/rl', 100, 1000), new Logger($this->dir . '/log.jsonl'), $key);
    }

    private function conversation(string $email, string $text, bool $escalated = false): ChatSession
    {
        $session = ChatSession::start();
        $session->customerId = (string) crc32($email);
        $session->customerEmail = $email;
        $session->escalated = $escalated;
        $session->addTranscript('user', $text);
        $session->addTranscript('assistant', 'Reply to: ' . $text);
        $this->sessions->save($session);

        return $session;
    }

    public function testStaffAccessNeedsTheKey(): void
    {
        self::assertSame(401, $this->staff()->authorize(null, '1.1.1.1')['status']);
        self::assertSame(401, $this->staff()->authorize('wrong-key-wrong-key-wrong-key', '1.1.1.1')['status']);
        self::assertNull($this->staff()->authorize(self::KEY, '1.1.1.1'));
    }

    public function testStaffPageIsOffWithoutAStrongKey(): void
    {
        self::assertFalse($this->staff('')->enabled());
        self::assertFalse($this->staff('short')->enabled());
        self::assertSame(404, $this->staff('')->authorize('', '1.1.1.1')['status']);
    }

    public function testEscalatedCustomerAndConversationViews(): void
    {
        $handedOff = $this->conversation('ana@clinic-a.test', 'Please remake my crown', true);
        $this->conversation('ana@clinic-a.test', 'Where is my order?');
        $this->conversation('bo@clinic-b.test', 'Hello');

        $escalated = $this->staff()->escalated('1.1.1.1')['conversations'];
        self::assertSame([$handedOff->id], array_column($escalated, 'id'));
        self::assertArrayNotHasKey('transcript', $escalated[0], 'the list shows summaries only');

        $customer = $this->staff()->customer('ANA@clinic-a.test', '1.1.1.1');
        self::assertCount(2, $customer['conversations']);
        self::assertStringNotContainsString('clinic-b', json_encode($customer));

        $one = $this->staff()->conversation($handedOff->id, '1.1.1.1')['conversation'];
        self::assertSame('Please remake my crown', $one['transcript'][0]['text']);
        self::assertSame(404, $this->staff()->conversation(str_repeat('a', 48), '1.1.1.1')['status']);
        self::assertSame(400, $this->staff()->customer('not-an-email', '1.1.1.1')['status']);

        self::assertStringContainsString('staff_view', (string) file_get_contents($this->dir . '/log.jsonl'), 'views are audited');
    }

    public function testHandoffMessageLinksToTheTranscript(): void
    {
        $notifier = new HandoffNotifier($this->dir . '/handoffs.jsonl', '', 'https://chat.example.run.app');
        @mkdir($this->dir);
        $notifier->notify(['session_id' => 'abc123', 'reason' => 'other']);
        $logged = json_decode(trim((string) file_get_contents($this->dir . '/handoffs.jsonl')), true);
        self::assertSame('https://chat.example.run.app/staff.html#conversation=abc123', $logged['transcript_url']);
    }
}
