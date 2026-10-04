<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

/** Counters in one locked JSON file, for local development and single-server installs. */
final class FileTokenCounter implements TokenCounter
{
    public function __construct(private readonly string $file)
    {
        if (!is_dir(dirname($this->file))) {
            mkdir(dirname($this->file), 0700, true);
        }
    }

    public function add(array $changes): array
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open token counter file');
        }
        try {
            flock($handle, LOCK_EX);
            $counters = json_decode((string) stream_get_contents($handle), true) ?: [];
            $now = time();
            $counters = array_filter($counters, static fn ($c) => ($c['expireAt'] ?? 0) > $now);

            $totals = [];
            foreach ($changes as $change) {
                $count = ($counters[$change['id']]['count'] ?? 0) + $change['amount'];
                $counters[$change['id']] = ['count' => $count, 'expireAt' => $change['expireAt']];
                $totals[] = $count;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($counters));

            return $totals;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
