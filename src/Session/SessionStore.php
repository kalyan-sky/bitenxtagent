<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

interface SessionStore
{
    /** Returns the stored session, or a fresh one if the ID is unknown, expired or malformed. */
    public function load(?string $id): ChatSession;

    public function save(ChatSession $session): void;
}
