<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * Small shared cache. Uses APCu (shared by all PHP workers in one Cloud Run
 * instance) when it is installed, otherwise only the current request. Never
 * store anything here that one customer must not see under another's key.
 */
final class Cache
{
    /** @var array<string, array{0: int, 1: mixed}> */
    private static array $local = [];

    public static function get(string $key): mixed
    {
        if (self::apcu()) {
            $value = apcu_fetch('bnx:' . $key, $found);

            return $found ? $value : null;
        }
        [$expires, $value] = self::$local[$key] ?? [0, null];

        return $expires >= time() ? $value : null;
    }

    public static function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if (self::apcu()) {
            apcu_store('bnx:' . $key, $value, $ttlSeconds);

            return;
        }
        self::$local[$key] = [time() + $ttlSeconds, $value];
    }

    /** For tests. */
    public static function clear(): void
    {
        self::$local = [];
        if (self::apcu()) {
            apcu_clear_cache();
        }
    }

    private static function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
