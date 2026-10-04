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

    public function query(string $collection, string $field, array $value, array $select, int $limit): array
    {
        $found = [];
        foreach ($this->docs as $key => $fields) {
            [$col, $id] = explode('/', $key, 2);
            if ($col === $collection && isset($fields[$field]) && $fields[$field] == $value) {
                $found[$id] = array_intersect_key($fields, array_flip($select));
            }
        }

        return array_slice($found, 0, $limit, true);
    }

    public function increment(array $counters): array
    {
        $counts = [];
        foreach ($counters as $c) {
            $key = $c['collection'] . '/' . $c['id'];
            $count = (int) ($this->docs[$key]['count']['integerValue'] ?? 0) + ($c['by'] ?? 1);
            $this->docs[$key] = ['count' => ['integerValue' => (string) $count], 'expireAt' => self::timestamp($c['expireAt'])];
            $counts[] = $count;
        }

        return $counts;
    }
}
