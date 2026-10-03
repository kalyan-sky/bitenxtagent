<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Session;

/**
 * File-backed session store (one JSON file per session) for local development
 * and single-server installs. On Cloud Run use FirestoreSessionStore.
 * $ttlSeconds is how long a conversation is kept after its last message.
 */
final class FileSessionStore implements SessionStore
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
        if ($id !== null && ChatSession::isValidId($id)) {
            $path = $this->path($id);
            if (is_file($path) && filemtime($path) > time() - $this->ttlSeconds) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data)) {
                    return ChatSession::fromArray($data);
                }
            }
        }

        return ChatSession::start();
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

    public function find(string $field, string|bool $value, int $limit): array
    {
        if (!in_array($field, self::FIND_FIELDS, true)) {
            throw new \InvalidArgumentException('Cannot search sessions by ' . $field);
        }
        $found = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if (filemtime($path) <= time() - $this->ttlSeconds) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data)) {
                continue;
            }
            $summary = ChatSession::fromArray($data)->summary();
            if ($summary[$field] === $value && $summary['messageCount'] > 0) {
                $found[] = $summary;
            }
        }
        usort($found, static fn ($a, $b) => $b['updatedAt'] <=> $a['updatedAt']);

        return array_slice($found, 0, $limit);
    }

    private function path(string $id): string
    {
        return $this->directory . '/' . $id . '.json';
    }
}
