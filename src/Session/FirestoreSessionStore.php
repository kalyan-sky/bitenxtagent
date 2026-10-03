<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;

/**
 * Conversations in Firestore, shared by every Cloud Run instance. Each one is
 * a document holding the conversation JSON, a few summary fields used for
 * listing, and an `expireAt` timestamp. A Firestore TTL policy on `expireAt`
 * deletes conversations when the retention period ends
 * (deploy/cloudrun-deploy.sh sets this up).
 */
final class FirestoreSessionStore implements SessionStore
{
    public function __construct(
        private readonly FirestoreClient $firestore,
        private readonly int $ttlSeconds,
        private readonly string $collection = 'chat_sessions',
    ) {
    }

    public function load(?string $id): ChatSession
    {
        if ($id === null || !ChatSession::isValidId($id)) {
            return ChatSession::start();
        }

        $fields = $this->firestore->get($this->collection, $id);
        // TTL deletion can lag by up to a day, so check expiry ourselves too.
        if ($fields === null || (strtotime($fields['expireAt']['timestampValue'] ?? '') ?: 0) < time()) {
            return ChatSession::start();
        }

        $data = json_decode($fields['data']['stringValue'] ?? '', true);

        return is_array($data) ? ChatSession::fromArray($data) : ChatSession::start();
    }

    public function save(ChatSession $session): void
    {
        $session->updatedAt = time();
        $summary = $session->summary();
        // The full conversation is one JSON string; the summary fields sit next
        // to it so conversations can be listed without downloading them.
        $this->firestore->set($this->collection, $session->id, [
            'data' => ['stringValue' => json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            'expireAt' => FirestoreClient::timestamp(time() + $this->ttlSeconds),
            'ownerKey' => ['stringValue' => $summary['ownerKey']],
            'customerEmail' => ['stringValue' => $summary['customerEmail']],
            'escalated' => ['booleanValue' => $summary['escalated']],
            'title' => ['stringValue' => $summary['title']],
            'updatedAt' => ['integerValue' => (string) $summary['updatedAt']],
            'messageCount' => ['integerValue' => (string) $summary['messageCount']],
        ]);
    }

    public function find(string $field, string|bool $value, int $limit): array
    {
        if (!in_array($field, self::FIND_FIELDS, true)) {
            throw new \InvalidArgumentException('Cannot search sessions by ' . $field);
        }

        // Equality filter only, so Firestore's automatic single-field indexes
        // are enough (no composite index to create). Newest-first sorting is
        // done here; 300 is far more conversations than one customer has in
        // the retention window.
        $rows = $this->firestore->query(
            $this->collection,
            $field,
            is_bool($value) ? ['booleanValue' => $value] : ['stringValue' => $value],
            ['ownerKey', 'customerEmail', 'escalated', 'title', 'updatedAt', 'messageCount', 'expireAt'],
            300,
        );

        $found = [];
        foreach ($rows as $id => $fields) {
            if ((strtotime($fields['expireAt']['timestampValue'] ?? '') ?: 0) < time()
                || (int) ($fields['messageCount']['integerValue'] ?? 0) === 0) {
                continue;
            }
            $found[] = [
                'id' => $id,
                'title' => (string) ($fields['title']['stringValue'] ?? ''),
                'updatedAt' => (int) ($fields['updatedAt']['integerValue'] ?? 0),
                'messageCount' => (int) ($fields['messageCount']['integerValue'] ?? 0),
                'escalated' => (bool) ($fields['escalated']['booleanValue'] ?? false),
                'ownerKey' => (string) ($fields['ownerKey']['stringValue'] ?? ''),
                'customerEmail' => (string) ($fields['customerEmail']['stringValue'] ?? ''),
            ];
        }
        usort($found, static fn ($a, $b) => $b['updatedAt'] <=> $a['updatedAt']);

        return array_slice($found, 0, $limit);
    }
}
