<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;
use Bitenxt\SupportAgent\Guardrails\FirestoreRateLimiter;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Session\FirestoreSessionStore;
use PHPUnit\Framework\TestCase;

final class FirestoreStoresTest extends TestCase
{
    public function testSessionRoundTripKeepsHistoryExactly(): void
    {
        $firestore = new InMemoryFirestore();
        $store = new FirestoreSessionStore($firestore, 3600);

        $session = $store->load(null);
        $session->customerId = '11';
        $session->messages = [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'get_recent_orders', 'input' => new \stdClass()],
            ]],
        ];
        $store->save($session);

        $loaded = $store->load($session->id);
        self::assertSame('11', $loaded->customerId);
        self::assertSame('sig', $loaded->messages[1]['content'][0]['signature']);
        self::assertSame('{}', json_encode($loaded->messages[1]['content'][1]['input']), 'empty tool input stays an object');
    }

    public function testExpiredMalformedAndUnknownIdsGetAFreshSession(): void
    {
        $firestore = new InMemoryFirestore();
        $store = new FirestoreSessionStore($firestore, 3600);
        $session = $store->load(null);
        $store->save($session);

        $firestore->docs['chat_sessions/' . $session->id]['expireAt'] = FirestoreClient::timestamp(time() - 1);
        self::assertNotSame($session->id, $store->load($session->id)->id, 'expired');
        self::assertNotSame('../../etc', $store->load('../../etc')->id, 'malformed');
        self::assertTrue(ChatSession::isValidId($store->load(str_repeat('a', 48))->id), 'unknown');
    }

    public function testFindListsACustomersConversationsNewestFirstWithoutLoadingThem(): void
    {
        $firestore = new InMemoryFirestore();
        $store = new FirestoreSessionStore($firestore, 3600);
        foreach (['11' => 'First', '22' => 'Other clinic', '11 ' => 'Second'] as $customer => $title) {
            $session = ChatSession::start();
            $session->customerId = trim((string) $customer);
            $session->customerEmail = trim((string) $customer) === '11' ? 'Ana@Clinic-A.test' : 'bo@clinic-b.test';
            $session->addTranscript('user', $title);
            $store->save($session);
            $firestore->docs['chat_sessions/' . $session->id]['updatedAt']['integerValue'] = (string) (time() - strlen($title));
        }
        $empty = ChatSession::start();
        $empty->customerId = '11';
        $store->save($empty);

        $found = $store->find('ownerKey', 'id:11', 10);
        self::assertSame(['First', 'Second'], array_column($found, 'title'), 'newest first, other clinic and empty excluded');
        self::assertSame(['ana@clinic-a.test'], array_values(array_unique(array_column($store->find('customerEmail', 'ana@clinic-a.test', 10), 'customerEmail'))));
    }

    public function testRateLimiterBlocksAfterThePerMinuteLimitAndKeysAreHashed(): void
    {
        $firestore = new InMemoryFirestore();
        $limiter = new FirestoreRateLimiter($firestore, 3, 100);

        self::assertTrue($limiter->hit('ip:1.2.3.4'));
        self::assertTrue($limiter->hit('ip:1.2.3.4'));
        self::assertTrue($limiter->hit('ip:1.2.3.4'));
        self::assertFalse($limiter->hit('ip:1.2.3.4'));
        self::assertTrue($limiter->hit('ip:5.6.7.8'), 'other keys are independent');
        self::assertStringNotContainsString('1.2.3.4', implode(' ', array_keys($firestore->docs)));
    }
}
