<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

/**
 * File-backed session store (one JSON file per session). Fine for a single
 * server; swap for Redis/MySQL behind the same two methods when you scale out.
 */
class SessionStore
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    public function load(?string $id): ChatSession
    {
        if ($id !== null && self::isValidId($id)) {
            $path = $this->path($id);
            if (is_file($path) && filemtime($path) > time() - $this->ttlSeconds) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    return ChatSession::fromArray($data);
                }
            }
        }

        // Unknown, expired or malformed IDs always get a brand-new session:
        // a client can never choose the ID of a session it is attached to.
        return new ChatSession(id: bin2hex(random_bytes(24)), createdAt: time());
    }

    public function save(ChatSession $session): void
    {
        $session->updatedAt = time();
        $path = $this->path($session->id);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($session->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public function delete(ChatSession $session): void
    {
        @unlink($this->path($session->id));
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }

    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{48}$/', $id);
    }
}
