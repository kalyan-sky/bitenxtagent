<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;

/** Firestore stand-in with the same semantics the stores rely on. */
final class InMemoryFirestore extends FirestoreClient
{
    /** @var array<string, array<string, mixed>> */
    public array $docs = [];

    public function __construct()
    {
        parent::__construct('test-project', '(default)', 'unused:0');
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->docs["$collection/$id"] ?? null;
    }

    public function set(string $collection, string $id, array $fields): void
    {
        $this->docs["$collection/$id"] = $fields;
    }

    public function increment(array $counters): array
    {
        $counts = [];
        foreach ($counters as $c) {
            $key = $c['collection'] . '/' . $c['id'];
            $count = (int) ($this->docs[$key]['count']['integerValue'] ?? 0) + 1;
            $this->docs[$key] = ['count' => ['integerValue' => (string) $count], 'expireAt' => self::timestamp($c['expireAt'])];
            $counts[] = $count;
        }

        return $counts;
    }
}
