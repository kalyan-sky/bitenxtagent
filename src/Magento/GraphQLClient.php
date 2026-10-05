<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

use Bitenxt\SupportAgent\Support\Http;
use Bitenxt\SupportAgent\Support\Timing;

/**
 * Minimal Magento GraphQL transport. It always sends the *customer's* bearer
 * token, so Magento's own authorization decides what data comes back; this
 * service never holds an admin or integration token.
 */
class GraphQLClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /** @var list<array<string, mixed>> errors that came with the last partial result */
    public array $lastPartialErrors = [];

    /**
     * @param array<string, mixed> $variables
     * @param bool $allowPartial return the data Magento did send when only some
     *                           fields errored (those fields come back null)
     * @return array<string, mixed> the `data` object
     */
    public function query(string $query, array $variables, ?string $customerToken, bool $allowPartial = false): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($customerToken !== null && $customerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $customerToken;
        }

        [$status, $body] = Timing::measure('magento', fn () => $this->send(
            json_encode(['query' => $query, 'variables' => (object) $variables], JSON_THROW_ON_ERROR),
            $headers,
        ));

        if ($status === 401 || $status === 403) {
            throw new MagentoAuthException('Magento rejected the customer token (HTTP ' . $status . ')');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new MagentoException('Magento returned non-JSON (HTTP ' . $status . ')');
        }

        $this->lastPartialErrors = [];
        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (($error['extensions']['category'] ?? '') === 'graphql-authorization') {
                    throw new MagentoAuthException('Magento authorization error: ' . ($error['message'] ?? ''));
                }
            }
            // A custom resolver failing on one field (e.g. a missing status
            // label) shouldn't throw away the rest of the order.
            if ($allowPartial && is_array($decoded['data'] ?? null) && $decoded['data'] !== []) {
                $this->lastPartialErrors = $decoded['errors'];

                return $decoded['data'];
            }
            throw new MagentoException('Magento GraphQL error: ' . json_encode($decoded['errors']));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * POSTs the request. Separate so tests can replay real Magento responses.
     *
     * @param list<string> $headers
     * @return array{0: int, 1: string} HTTP status and body
     */
    protected function send(string $body, array $headers): array
    {
        $ch = Http::handle($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            throw new MagentoException('Magento request failed: ' . $curlError);
        }

        return [$status, (string) $response];
    }
}
