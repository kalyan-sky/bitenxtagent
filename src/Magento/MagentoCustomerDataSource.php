<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

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

    public function __construct(private readonly GraphQLClient $client)
    {
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
        $query = 'query ($pageSize: Int!) { customer { orders(currentPage: 1, pageSize: $pageSize, '
            . 'sort: { sort_field: CREATED_AT, sort_direction: DESC }) { items { '
            . self::ORDER_FIELDS . ' } } } }';
        $data = $this->client->query($query, ['pageSize' => $limit], $token);

        return array_values($data['customer']['orders']['items'] ?? []);
    }

    public function findOwnOrder(string $token, string $orderNumber): ?array
    {
        // Querying through `customer { orders }` means Magento only searches
        // the token owner's orders: another clinic's order number finds nothing.
        $query = 'query ($number: String!) { customer { orders(filter: { number: { eq: $number } }) { items { '
            . self::ORDER_FIELDS . ' } } } }';
        $data = $this->client->query($query, ['number' => $orderNumber], $token);

        foreach ($data['customer']['orders']['items'] ?? [] as $order) {
            // Belt and braces: never trust a filter we did not write.
            if (is_array($order) && (string) ($order['number'] ?? '') === $orderNumber) {
                return $order;
            }
        }

        return null;
    }

    public function findOwnOrdersByPatient(string $token, string $patientName, int $limit): array
    {
        // customerAllOrders is the Pro order-history query; it takes no
        // customer ID, so it is scoped to the token.
        $query = 'query ($name: String!, $pageSize: Int!) { customerAllOrders(currentPage: 1, pageSize: $pageSize, '
            . 'filter: { patient_name: $name }) { items { number order_date order_status_title patient_name } } }';
        $data = $this->client->query($query, ['name' => $patientName, 'pageSize' => $limit], $token);

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
