<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

interface SessionStore
{
    /** Returns the stored session, or a fresh one if the ID is unknown, expired or malformed. */
    public function load(?string $id): ChatSession;

    public function save(ChatSession $session): void;

    /**
     * Summaries (see ChatSession::summary()) of one customer's conversations,
     * newest first. Only conversations with at least one message.
     *
     * @return list<array{id: string, title: string, updatedAt: int, messageCount: int, ownerKey: string}>
     */
    public function findByOwner(string $ownerKey, int $limit): array;

    /** The customer's most recently saved conversation, or null if they have none. */
    public function latest(string $ownerKey): ?ChatSession;
}
