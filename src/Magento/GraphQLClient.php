<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

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

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the `data` object
     */
    public function query(string $query, array $variables, ?string $customerToken): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($customerToken !== null && $customerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $customerToken;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(
                ['query' => $query, 'variables' => (object) $variables],
                JSON_THROW_ON_ERROR,
            ),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new MagentoException('Magento request failed: ' . $curlError);
        }
        if ($status === 401 || $status === 403) {
            throw new MagentoAuthException('Magento rejected the customer token (HTTP ' . $status . ')');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new MagentoException('Magento returned non-JSON (HTTP ' . $status . ')');
        }

        if (!empty($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (($error['extensions']['category'] ?? '') === 'graphql-authorization') {
                    throw new MagentoAuthException('Magento authorization error: ' . ($error['message'] ?? ''));
                }
            }
            throw new MagentoException('Magento GraphQL error: ' . json_encode($decoded['errors']));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
