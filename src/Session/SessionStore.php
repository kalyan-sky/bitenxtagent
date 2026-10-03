<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

interface SessionStore
{
    /** Fields that find() can filter on. */
    public const FIND_FIELDS = ['ownerKey', 'customerEmail', 'escalated'];

    /** Returns the stored session, or a fresh one if the ID is unknown, expired or malformed. */
    public function load(?string $id): ChatSession;

    public function save(ChatSession $session): void;

    /**
     * Conversation summaries (see ChatSession::summary()) where $field equals
     * $value, newest first. Only conversations with at least one message.
     *
     * @return list<array{id: string, title: string, updatedAt: int, messageCount: int, escalated: bool,
     *                    ownerKey: string, customerEmail: string}>
     */
    public function find(string $field, string|bool $value, int $limit): array;
}
