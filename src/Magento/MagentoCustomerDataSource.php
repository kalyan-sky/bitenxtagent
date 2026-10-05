<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

use Bitenxt\SupportAgent\Support\Logger;

/**
 * Fixed, hand-written GraphQL documents. The model never writes GraphQL; it can
 * only trigger these queries through the tools in Agent\SupportTools.
 *
 * Field names follow "BiteNXT Magento Custom API Reference" (Sep 2026,
 * Magento 2.4.6). Bitenxt's AdminOrder module overrides Customer.orders and the
 * order types, so confirm the selections below with an introspection query on
 * UAT before going live (see README → "Verify the GraphQL fields").
 *
 * Deliberately NOT used, because they take a raw ID that the resolver may not
 * check against the token: salesOrder, serviceOrder, getCustomerAddresses,
 * fetchPatients, fetchAttachment, listServiceFiles, listCartServiceFiles.
 * No admin operation is ever called.
 */
final class MagentoCustomerDataSource implements CustomerDataSource
{
    private const ORDER_FIELDS = <<<'GQL'
        number
        order_date
        status
        status_title
        patient_name
        carrier
        shipping_method
        total { grand_total { value currency } }
        items { product_name quantity_ordered quantity_shipped }
        shipments { tracking { carrier title number } }
        GQL;

    /** Standard Magento order fields only, used if the full set is rejected. */
    private const CORE_ORDER_FIELDS = <<<'GQL'
        number
        order_date
        status
        total { grand_total { value currency } }
        items { product_name quantity_ordered }
        GQL;

    /** Magento's default order number length (increment IDs like 000000726). */
    private const ORDER_NUMBER_LENGTH = 9;

    public function __construct(
        private readonly GraphQLClient $client,
        private readonly ?Logger $logger = null,
    ) {
    }

    public function currentCustomer(string $token): array
    {
        $data = $this->client->query(
            'query { customer { customer_id firstname email business_name } }',
            [],
            $token,
        );
        $customer = $data['customer'] ?? null;
        if (!is_array($customer) || empty($customer['email'])) {
            throw new MagentoAuthException('Token did not resolve to a customer');
        }

        return [
            'customer_id' => (string) ($customer['customer_id'] ?? ''),
            'firstname' => (string) ($customer['firstname'] ?? ''),
            'email' => (string) $customer['email'],
            'business_name' => (string) ($customer['business_name'] ?? ''),
        ];
    }

    public function recentOrders(string $token, int $limit): array
    {
        $data = $this->queryOrders(
            'query ($pageSize: Int!) { customer { orders(currentPage: 1, pageSize: $pageSize, '
                . 'sort: { sort_field: CREATED_AT, sort_direction: DESC }) { items { %s } } } }',
            ['pageSize' => $limit],
            $token,
        );

        return array_values($data['customer']['orders']['items'] ?? []);
    }

    public function findOwnOrder(string $token, string $orderNumber): ?array
    {
        // Customers often type "726" for order 000000726: try the number as
        // typed, then Magento's zero-padded form.
        $candidates = [$orderNumber];
        if (ctype_digit($orderNumber) && strlen($orderNumber) < self::ORDER_NUMBER_LENGTH) {
            $candidates[] = str_pad($orderNumber, self::ORDER_NUMBER_LENGTH, '0', STR_PAD_LEFT);
        }

        foreach ($candidates as $candidate) {
            // Querying through `customer { orders }` means Magento only searches
            // the token owner's orders: another clinic's order number finds nothing.
            $data = $this->queryOrders(
                'query ($number: String!) { customer { orders(filter: { number: { eq: $number } }) { items { %s } } } }',
                ['number' => $candidate],
                $token,
            );
            foreach ($data['customer']['orders']['items'] ?? [] as $order) {
                // Belt and braces: never trust a filter we did not write.
                if (is_array($order) && (string) ($order['number'] ?? '') === $candidate) {
                    return $order;
                }
            }
        }

        return null;
    }

    /**
     * Runs an order query with the full field set; if Magento rejects it
     * outright (e.g. a field doesn't exist after a module change), retries
     * with standard Magento fields only. Fields whose resolver errors come
     * back empty instead of failing the whole lookup.
     *
     * @param string $template query with %s where the order fields go
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function queryOrders(string $template, array $variables, string $token): array
    {
        try {
            $data = $this->client->query(sprintf($template, self::ORDER_FIELDS), $variables, $token, true);
        } catch (MagentoAuthException $e) {
            throw $e;
        } catch (MagentoException $e) {
            $this->logger?->log('magento_order_fields_fallback', ['detail' => substr($e->getMessage(), 0, 500)]);
            $data = $this->client->query(sprintf($template, self::CORE_ORDER_FIELDS), $variables, $token, true);
        }

        if ($this->client->lastPartialErrors !== []) {
            // Field paths only (no order data), so the broken resolver can be fixed in Magento.
            $this->logger?->log('magento_partial', ['fields' => array_values(array_unique(array_map(
                static fn ($e) => implode('.', array_filter((array) ($e['path'] ?? []), 'is_string')),
                $this->client->lastPartialErrors,
            )))]);
        }

        return $data;
    }

    public function findOwnOrdersByPatient(string $token, string $patientName, int $limit): array
    {
        // customerAllOrders is the Pro order-history query; it takes no
        // customer ID, so it is scoped to the token.
        $query = 'query ($name: String!, $pageSize: Int!) { customerAllOrders(currentPage: 1, pageSize: $pageSize, '
            . 'filter: { patient_name: $name }) { items { number order_date order_status_title patient_name } } }';
        $data = $this->client->query($query, ['name' => $patientName, 'pageSize' => $limit], $token, true);

        return array_values($data['customerAllOrders']['items'] ?? []);
    }

    public function orderFollowUps(string $token, string $orderNumber): array
    {
        $query = 'query ($incrementId: String!) { getOrderFollowUps(increment_id: $incrementId) '
            . '{ customer_id sku note created_at } }';
        $data = $this->client->query($query, ['incrementId' => $orderNumber], $token);

        return array_values($data['getOrderFollowUps'] ?? []);
    }

    public function searchProducts(string $token, string $phrase, int $limit): array
    {
        $query = 'query ($search: String!, $pageSize: Int!) { products(search: $search, pageSize: $pageSize) '
            . '{ items { name sku stock_status url_key '
            . 'price_range { minimum_price { final_price { value currency } } } } } }';
        $data = $this->client->query($query, ['search' => $phrase, 'pageSize' => $limit], $token);

        return array_values($data['products']['items'] ?? []);
    }
}
