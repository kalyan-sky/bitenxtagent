<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

use Bitenxt\SupportAgent\Gcp\FirestoreClient;

/** Counters in Firestore, shared by every Cloud Run instance; old ones expire via TTL on `expireAt`. */
final class FirestoreTokenCounter implements TokenCounter
{
    public function __construct(
        private readonly FirestoreClient $firestore,
        private readonly string $collection = 'chat_token_usage',
    ) {
    }

    public function add(array $changes): array
    {
        return $this->firestore->increment(array_map(fn (array $c) => [
            'collection' => $this->collection,
            'id' => $c['id'],
            'expireAt' => $c['expireAt'],
            'by' => $c['amount'],
        ], $changes));
    }
}
