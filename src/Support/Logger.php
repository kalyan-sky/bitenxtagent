<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * JSON-lines audit log. Never logs tokens, API keys, or raw chat text;
 * customer identity is recorded as a hash so incidents can be traced without
 * the log becoming a store of personal data.
 */
class Logger
{
    public function __construct(private readonly string $file)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $event, array $context = []): void
    {
        $line = json_encode(['ts' => date(DATE_ATOM), 'event' => $event] + $context, JSON_UNESCAPED_SLASHES);
        file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function pseudonym(string $value): string
    {
        return $value === '' ? '' : substr(hash('sha256', $value), 0, 16);
    }
}
