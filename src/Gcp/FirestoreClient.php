<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Gcp;

use Bitenxt\SupportAgent\Support\Cache;
use Bitenxt\SupportAgent\Support\Http;
use Bitenxt\SupportAgent\Support\Timing;

/**
 * Tiny Firestore REST client: just the calls the chatbot needs, without the
 * gRPC extension the official library requires. On Cloud Run it
 * authenticates as the service's own service account via the metadata
 * server, so no key file is needed. Set FIRESTORE_EMULATOR_HOST to use the
 * local emulator instead (no auth).
 */
class FirestoreClient
{
    private const METADATA = 'http://metadata.google.internal/computeMetadata/v1/';


    private readonly string $baseUrl;
    private readonly string $documentsPath;

    public function __construct(
        string $projectId = '',
        string $database = '(default)',
        private readonly string $emulatorHost = '',
        private readonly int $timeoutSeconds = 5,
    ) {
        $projectId = $projectId !== '' ? $projectId : $this->metadata('project/project-id');
        $this->documentsPath = sprintf('projects/%s/databases/%s/documents', $projectId, $database);
        $this->baseUrl = ($emulatorHost !== '' ? 'http://' . $emulatorHost : 'https://firestore.googleapis.com') . '/v1/';
    }

    /** @return array<string, mixed>|null the document's `fields`, or null if it does not exist */
    public function get(string $collection, string $id): ?array
    {
        [$status, $body] = $this->request('GET', $this->documentsPath . '/' . $collection . '/' . $id);
        if ($status === 404) {
            return null;
        }
        $this->assertOk($status, $body);

        return $body['fields'] ?? [];
    }

    /** Creates or replaces a document. @param array<string, mixed> $fields Firestore-typed values */
    public function set(string $collection, string $id, array $fields): void
    {
        [$status, $body] = $this->request('PATCH', $this->documentsPath . '/' . $collection . '/' . $id, ['fields' => $fields]);
        $this->assertOk($status, $body);
    }

    /**
     * Documents in a collection where $field equals $value, returning only
     * the $select fields. Keyed by document ID.
     *
     * @param array<string, mixed> $value Firestore-typed value, e.g. ['stringValue' => 'x']
     * @param list<string> $select
     * @return array<string, array<string, mixed>>
     */
    public function query(string $collection, string $field, array $value, array $select, int $limit): array
    {
        [$status, $body] = $this->request('POST', $this->documentsPath . ':runQuery', ['structuredQuery' => [
            'from' => [['collectionId' => $collection]],
            'where' => ['fieldFilter' => ['field' => ['fieldPath' => $field], 'op' => 'EQUAL', 'value' => $value]],
            'select' => ['fields' => array_map(static fn ($f) => ['fieldPath' => $f], $select)],
            'limit' => $limit,
        ]]);
        $this->assertOk($status, $body);

        $documents = [];
        foreach ($body as $row) {
            if (isset($row['document']['name'])) {
                $id = substr($row['document']['name'], strrpos($row['document']['name'], '/') + 1);
                $documents[$id] = $row['document']['fields'] ?? [];
            }
        }

        return $documents;
    }

    /**
     * Atomically adds `by` (default 1, may be negative) to the `count` field
     * of each document, creating it if needed, and sets its `expireAt`. All
     * counters change in one commit. Returns the new counts, in order.
     *
     * @param list<array{collection: string, id: string, expireAt: int, by?: int}> $counters
     * @return list<int>
     */
    public function increment(array $counters): array
    {
        $writes = [];
        foreach ($counters as $counter) {
            $writes[] = [
                // An update with a field mask and no precondition is an upsert.
                'update' => [
                    'name' => $this->documentsPath . '/' . $counter['collection'] . '/' . $counter['id'],
                    'fields' => ['expireAt' => self::timestamp($counter['expireAt'])],
                ],
                'updateMask' => ['fieldPaths' => ['expireAt']],
                'updateTransforms' => [['fieldPath' => 'count', 'increment' => ['integerValue' => (string) ($counter['by'] ?? 1)]]],
            ];
        }
        [$status, $body] = $this->request('POST', $this->documentsPath . ':commit', ['writes' => $writes]);
        $this->assertOk($status, $body);

        return array_map(
            static fn (array $result) => (int) ($result['transformResults'][0]['integerValue'] ?? PHP_INT_MAX),
            $body['writeResults'] ?? [],
        );
    }

    /** @return array{timestampValue: string} */
    public static function timestamp(int $unixTime): array
    {
        return ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z', $unixTime)];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->emulatorHost === '') {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken();
        }
        $ch = Http::handle($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        $raw = Timing::measure('firestore', static fn () => curl_exec($ch));
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($raw === false) {
            throw new \RuntimeException('Firestore request failed: ' . $error);
        }

        return [$status, json_decode((string) $raw, true) ?: []];
    }

    /** @param array<string, mixed> $body */
    private function assertOk(int $status, array $body): void
    {
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Firestore error HTTP ' . $status . ': ' . ($body['error']['message'] ?? 'unknown'));
        }
    }

    private function accessToken(): string
    {
        // Shared by the instance's PHP workers (APCu); tokens last about an hour.
        $cached = Cache::get('gcp_access_token');
        if (is_string($cached)) {
            return $cached;
        }
        $token = json_decode($this->metadata('instance/service-accounts/default/token'), true);
        if (!isset($token['access_token'])) {
            throw new \RuntimeException('Could not get a service account token from the metadata server');
        }
        Cache::set('gcp_access_token', $token['access_token'], max(30, (int) ($token['expires_in'] ?? 300) - 120));

        return $token['access_token'];
    }

    private function metadata(string $path): string
    {
        $ch = curl_init(self::METADATA . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);
        $value = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($value === false || $status !== 200) {
            throw new \RuntimeException('Metadata server unavailable for ' . $path . ' (set GCP_PROJECT when running outside Google Cloud)');
        }

        return (string) $value;
    }
}
