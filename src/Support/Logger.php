<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * JSON-lines audit log. Never logs tokens, API keys, or raw chat text;
 * customer identity is recorded as a hash so incidents can be traced without
 * the log becoming a store of personal data.
 *
 * Pass "php://stderr" on Cloud Run: each line becomes a structured entry in
 * Cloud Logging (the `severity` and `message` fields are picked up natively).
 */
class Logger
{
    private const WARNINGS = ['suspicious_input', 'reply_blocked', 'reply_redacted', 'rate_limited', 'token_rejected',
        'foreign_row_dropped', 'session_reset', 'handoff'];
    private const ERRORS = ['magento_error', 'magento_auth_error', 'claude_error', 'claude_unavailable', 'storage_error'];

    public function __construct(private readonly string $file)
    {
        $dir = dirname($this->file);
        if (!self::isStream($this->file) && !is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    /** @param array<string, mixed> $context */
    public function log(string $event, array $context = []): void
    {
        $severity = in_array($event, self::ERRORS, true) ? 'ERROR' : (in_array($event, self::WARNINGS, true) ? 'WARNING' : 'INFO');
        $line = json_encode(
            ['ts' => date(DATE_ATOM), 'severity' => $severity, 'message' => $event, 'event' => $event] + $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        self::append($this->file, $line);
    }

    /** Appends one line to a file, or writes it to a stream such as php://stderr. */
    public static function append(string $target, string $line): void
    {
        file_put_contents($target, $line . "\n", self::isStream($target) ? 0 : FILE_APPEND | LOCK_EX);
    }

    private static function isStream(string $target): bool
    {
        return str_starts_with($target, 'php://');
    }

    public static function pseudonym(string $value): string
    {
        return $value === '' ? '' : substr(hash('sha256', $value), 0, 16);
    }
}
