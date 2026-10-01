<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Guardrails;

/**
 * Fixed-window counters kept in small local files (development / single server).
 * On Cloud Run use FirestoreRateLimiter so all instances share the counters.
 */
final class FileRateLimiter implements RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $perMinute,
        private readonly int $perDay,
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    /** Records a hit and returns false if the key is over either limit. */
    public function hit(string $key): bool
    {
        $now = time();
        $path = $this->directory . '/' . hash('sha256', $key) . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return true; // fail open on storage problems; the model call has its own limits
        }

        try {
            flock($handle, LOCK_EX);
            $state = json_decode((string) stream_get_contents($handle), true) ?: [];
            $minute = intdiv($now, 60);
            $day = intdiv($now, 86400);

            $state['m'] = ($state['mw'] ?? null) === $minute ? ($state['m'] ?? 0) + 1 : 1;
            $state['d'] = ($state['dw'] ?? null) === $day ? ($state['d'] ?? 0) + 1 : 1;
            $state['mw'] = $minute;
            $state['dw'] = $day;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));

            return $state['m'] <= $this->perMinute && $state['d'] <= $this->perDay;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
