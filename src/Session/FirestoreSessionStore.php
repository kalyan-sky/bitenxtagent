<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;

/**
 * Sessions in Firestore, shared by every Cloud Run instance. Each session is
 * one document holding the session JSON plus an `expireAt` timestamp; turn on
 * a Firestore TTL policy for `expireAt` so old chats are deleted automatically
 * (deploy/cloudrun-deploy.sh does this).
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
        $this->firestore->set($this->collection, $session->id, [
            'data' => ['stringValue' => json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            'expireAt' => FirestoreClient::timestamp(time() + $this->ttlSeconds),
        ]);
    }
}
