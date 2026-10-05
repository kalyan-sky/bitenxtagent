<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Support;

/**
 * Milliseconds spent per stage (magento, firestore, llm) in the current
 * request, so every "turn" log line shows where the time went.
 */
final class Timing
{
    /** @var array<string, float> */
    private static array $totals = [];
    /** @var array<string, int> */
    private static array $counts = [];

    public static function reset(): void
    {
        self::$totals = [];
        self::$counts = [];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function measure(string $stage, callable $work): mixed
    {
        $start = hrtime(true);
        try {
            return $work();
        } finally {
            self::$totals[$stage] = (self::$totals[$stage] ?? 0) + (hrtime(true) - $start) / 1e6;
            self::$counts[$stage] = (self::$counts[$stage] ?? 0) + 1;
        }
    }

    /** @return array<string, int> e.g. ['magento_ms' => 420, 'magento_calls' => 2, ...] */
    public static function summary(): array
    {
        $out = [];
        foreach (self::$totals as $stage => $ms) {
            $out[$stage . '_ms'] = (int) round($ms);
            $out[$stage . '_calls'] = self::$counts[$stage];
        }

        return $out;
    }
}
