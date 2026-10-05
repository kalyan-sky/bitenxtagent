<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

use Bitenxt\SupportAgent\Support\Cache;
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

        return array_values(array_filter($data['customer']['orders']['items'] ?? [], 'is_array')); // a failed row comes back as null
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

    public function orderStats(string $token): array
    {
        // Totals come from Magento; the per-status split from the latest 100 orders.
        $data = $this->client->query(
            'query { customer { orders(currentPage: 1, pageSize: 100, sort: { sort_field: CREATED_AT, sort_direction: DESC }) '
                . '{ total_count items { status } } } }',
            [],
            $token,
            true,
        );
        $orders = $data['customer']['orders'] ?? [];
        $byStatus = [];
        foreach ($orders['items'] ?? [] as $order) {
            $status = is_array($order) ? (string) ($order['status'] ?? '') : '';
            if ($status !== '') {
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            }
        }
        arsort($byStatus);
        $counted = count($orders['items'] ?? []);

        return ['total' => (int) ($orders['total_count'] ?? $counted), 'counted' => $counted, 'by_status' => $byStatus];
    }

    public function patientsFromOrders(string $token, int $orders): array
    {
        $rows = [];
        foreach ([
            'customerAllOrders' => 'query ($pageSize: Int!) { customerAllOrders(currentPage: 1, pageSize: $pageSize) { items { number order_date patient_name } } }',
            'customer' => 'query ($pageSize: Int!) { customer { orders(currentPage: 1, pageSize: $pageSize, sort: { sort_field: CREATED_AT, sort_direction: DESC }) '
                . '{ items { number order_date patient_name } } } }',
        ] as $source => $query) {
            $rows = $this->ordersOrEmpty($query, ['pageSize' => $orders], $source, $token);
            $named = count(array_filter($rows, static fn ($o) => trim((string) ($o['patient_name'] ?? '')) !== ''));
            $this->logPatientSearch('list_' . $source, $rows, $named);
            if ($named > 0) {
                return $rows;
            }
        }

        return $rows;
    }

    public function availableCoupons(string $token): array
    {
        $data = $this->queryDroppingUnknownFields(
            'query { availableCoupons { %s } }',
            ['name', 'code', 'coupon_code', 'description', 'discount_type', 'discount_amount', 'from_date', 'to_date'],
            [],
            $token,
        );

        return array_values(array_filter($data['availableCoupons'] ?? [], 'is_array'));
    }

    public function cartSummary(string $token): ?array
    {
        $data = $this->queryDroppingUnknownFields(
            'query { customerCart { %s } }',
            ['id', 'total_quantity', 'items { quantity product { name sku } }', 'prices { grand_total { value currency } }',
                'applied_coupons { code }', 'custom_shipping_attributes { doctor_name }'],
            [],
            $token,
        );
        $cart = $data['customerCart'] ?? null;
        if (!is_array($cart) || empty($cart['id'])) {
            return null;
        }

        // The cart ID comes from the customer's own cart above, never from the
        // chat, so these ID-based lookups can only ever read this customer's cart.
        $cartId = (string) $cart['id'];
        $extra = [
            'patient' => ['query ($id: String) { getPatientFromCart(cart_id: $id) { name } }', 'getPatientFromCart'],
            'doctor' => ['query ($id: String!) { getDoctorFromCart(cart_id: $id) { doctor_name } }', 'getDoctorFromCart'],
            'scan' => ['query ($id: String!) { kixrScanStatus(cart_id: $id) { status files { status } } }', 'kixrScanStatus'],
        ];
        foreach ($extra as $key => [$query, $field]) {
            try {
                $cart[$key] = $this->client->query($query, ['id' => $cartId], $token, true)[$field] ?? null;
            } catch (MagentoAuthException $e) {
                throw $e;
            } catch (MagentoException $e) {
                $this->logger?->log('magento_cart_detail_unavailable', ['detail' => $key, 'error' => substr($e->getMessage(), 0, 300)]);
                $cart[$key] = null;
            }
        }

        return $cart;
    }

    /**
     * For queries on Bitenxt types whose exact field names vary between
     * environments: fields Magento says do not exist are dropped and the query
     * is retried once, instead of the whole answer failing.
     *
     * @param list<string> $fields selections; a nested one starts with its field name
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function queryDroppingUnknownFields(string $template, array $fields, array $variables, string $token): array
    {
        // Fields this environment rejected before are left out straight away.
        $cacheKey = 'unknown_fields:' . md5($template);
        $known = Cache::get($cacheKey);
        $unknownBefore = is_array($known) ? $known : [];
        $fields = array_values(array_filter($fields, static fn ($f) => !in_array(strtok($f, ' {'), $unknownBefore, true)));
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->client->query(sprintf($template, implode(' ', $fields)), $variables, $token, true);
            } catch (MagentoAuthException $e) {
                throw $e;
            } catch (MagentoException $e) {
                preg_match_all('/Cannot query field \\\\?"(\w+)\\\\?"/', $e->getMessage(), $m);
                $unknown = array_unique($m[1]);
                $kept = array_values(array_filter($fields, static fn ($f) => !in_array(strtok($f, ' {'), $unknown, true)));
                if ($attempt > 0 || $unknown === [] || $kept === [] || $kept === $fields) {
                    throw $e;
                }
                $this->logger?->log('magento_unknown_fields', ['fields' => array_values($unknown)]);
                Cache::set($cacheKey, array_values(array_unique(array_merge($unknownBefore, $unknown))), 3600);
                $fields = $kept;
            }
        }
    }

    public function ownPatients(string $token, string $customerId): array
    {
        if (!ctype_digit($customerId)) {
            return [];
        }
        // fetchPatients takes a customer ID. We only ever pass the ID Magento
        // returned for this token, so it can only list this clinic's patients.
        try {
            $data = $this->queryDroppingUnknownFields(
                'query ($id: Int!) { fetchPatients(id: $id) { %s } }',
                ['id', 'patient_id', 'entity_id', 'name'],
                ['id' => (int) $customerId],
                $token,
            );
        } catch (MagentoAuthException $e) {
            throw $e;
        } catch (MagentoException $e) {
            $this->logger?->log('magento_error', ['query' => 'fetchPatients', 'detail' => substr($e->getMessage(), 0, 400)]);

            return [];
        }

        $patients = [];
        foreach ($data['fetchPatients'] ?? [] as $row) {
            $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
            if ($name !== '') {
                $patients[] = ['id' => (string) ($row['id'] ?? $row['patient_id'] ?? $row['entity_id'] ?? ''), 'name' => $name];
            }
        }

        return $patients;
    }

    public function findOwnOrdersByPatient(string $token, string $patientName, int $limit): array
    {
        $needle = mb_strtolower(trim($patientName));
        $matches = static fn (array $rows) => array_values(array_filter(
            $rows,
            static fn (array $o) => str_contains(mb_strtolower(trim((string) ($o['patient_name'] ?? ''))), $needle),
        ));

        // 1. Pro's own order-history search. customerAllOrders takes no
        //    customer ID, so it only ever searches this customer's orders.
        $rows = $this->ordersOrEmpty(
            'query ($name: String!, $pageSize: Int!) { customerAllOrders(currentPage: 1, pageSize: $pageSize, '
                . 'filter: { patient_name: $name }) { items { number order_date order_status_title patient_name } } }',
            ['name' => trim($patientName), 'pageSize' => $limit],
            'customerAllOrders',
            $token,
        );
        if ($rows !== []) {
            $this->logPatientSearch('search_filter', $rows, count($rows));

            return array_slice($rows, 0, $limit);
        }

        // 2. That search may need the full name, or be case-sensitive: scan the
        //    latest orders ourselves for part of the name, in any case.
        foreach ([
            'customerAllOrders' => 'query { customerAllOrders(currentPage: 1, pageSize: 100) { items { number order_date order_status_title patient_name } } }',
            'customer' => 'query { customer { orders(currentPage: 1, pageSize: 100, sort: { sort_field: CREATED_AT, sort_direction: DESC }) '
                . '{ items { number order_date status patient_name } } } }',
        ] as $source => $query) {
            $rows = $this->ordersOrEmpty($query, [], $source, $token);
            $found = $matches($rows);
            $this->logPatientSearch('scan_' . $source, $rows, count($found));
            if ($found !== []) {
                usort($found, static fn ($a, $b) => strcmp((string) ($b['order_date'] ?? ''), (string) ($a['order_date'] ?? '')));

                return array_slice($found, 0, $limit);
            }
        }

        return [];
    }

    /**
     * Order rows from customerAllOrders or customer.orders; an error (other
     * than an expired login) counts as no rows so the next way is tried.
     *
     * @param array<string, mixed> $variables
     * @return list<array<string, mixed>>
     */
    private function ordersOrEmpty(string $query, array $variables, string $source, string $token): array
    {
        try {
            $data = $this->client->query($query, $variables, $token, true);
        } catch (MagentoAuthException $e) {
            throw $e;
        } catch (MagentoException $e) {
            $this->logger?->log('magento_error', ['query' => $source, 'detail' => substr($e->getMessage(), 0, 400)]);

            return [];
        }
        $items = $source === 'customer' ? ($data['customer']['orders']['items'] ?? []) : ($data[$source]['items'] ?? []);

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * How the patient search went, without any names: if rows come back but
     * none has a patient name, Magento's patient_name field is failing.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function logPatientSearch(string $strategy, array $rows, int $matched): void
    {
        $this->logger?->log('patient_search', [
            'strategy' => $strategy,
            'orders' => count($rows),
            'orders_with_patient_name' => count(array_filter($rows, static fn ($o) => trim((string) ($o['patient_name'] ?? '')) !== '')),
            'matched' => $matched,
            'partial_errors' => array_values(array_unique(array_map(
                static fn ($e) => implode('.', array_filter((array) ($e['path'] ?? []), 'is_string')),
                $this->client->lastPartialErrors,
            ))),
        ]);
    }

    public function orderFollowUps(string $token, string $orderNumber): array
    {
        $query = 'query ($incrementId: String!) { getOrderFollowUps(increment_id: $incrementId) '
            . '{ customer_id sku note created_at } }';
        $data = $this->client->query($query, ['incrementId' => $orderNumber], $token);

        return array_values(array_filter($data['getOrderFollowUps'] ?? [], 'is_array')); // a failed row comes back as null
    }

    /**
     * Full-text search first; if that finds nothing, retry with the singular
     * form ("Aligners" -> "Aligner") and then with a name match, because
     * Magento's search index often misses plurals and partial names.
     */
    public function searchProducts(string $token, string $phrase, int $limit): array
    {
        $fields = '{ items { name sku stock_status url_key '
            . 'price_range { minimum_price { final_price { value currency } } } } }';
        $attempts = [['search', $phrase]];
        $singular = self::singular($phrase);
        if ($singular !== $phrase) {
            $attempts[] = ['search', $singular];
        }
        $attempts[] = ['name', $singular];

        foreach ($attempts as [$mode, $words]) {
            $query = $mode === 'search'
                ? 'query ($q: String!, $pageSize: Int!) { products(search: $q, pageSize: $pageSize) ' . $fields . ' }'
                : 'query ($q: String!, $pageSize: Int!) { products(filter: { name: { match: $q } }, pageSize: $pageSize) ' . $fields . ' }';
            $data = $this->client->query($query, ['q' => $words, 'pageSize' => $limit], $token, true);
            $items = array_values(array_filter($data['products']['items'] ?? [], 'is_array'));
            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * The catalog's categories (two levels below the root) with a few product
     * names in each, for "what do you offer?" questions.
     */
    public function catalogOverview(string $token, int $productsPerCategory): array
    {
        $products = 'products(pageSize: ' . max(1, min(20, $productsPerCategory)) . ') { total_count items { name } }';
        $query = 'query { categoryList { name children { name include_in_menu ' . $products
            . ' children { name include_in_menu ' . $products . ' } } } }';
        $data = $this->client->query($query, [], $token, true);

        $categories = [];
        $add = static function (array $category, string $parent) use (&$categories): void {
            $name = trim((string) ($category['name'] ?? ''));
            $names = array_values(array_filter(array_map(
                static fn ($p) => is_array($p) ? trim((string) ($p['name'] ?? '')) : '',
                $category['products']['items'] ?? [],
            )));
            if ($name === '' || ($category['include_in_menu'] ?? 1) === 0 || $names === []) {
                return;
            }
            $categories[] = [
                'category' => $parent !== '' ? $parent . ' / ' . $name : $name,
                'product_count' => (int) ($category['products']['total_count'] ?? count($names)),
                'products' => $names,
            ];
        };
        foreach ($data['categoryList'] ?? [] as $root) {
            foreach (is_array($root) ? ($root['children'] ?? []) : [] as $top) {
                if (!is_array($top)) {
                    continue;
                }
                $add($top, '');
                foreach ($top['children'] ?? [] as $child) {
                    if (is_array($child)) {
                        $add($child, (string) ($top['name'] ?? ''));
                    }
                }
            }
        }

        return $categories;
    }

    private static function singular(string $phrase): string
    {
        return (string) preg_replace_callback('/\b(\p{L}{3,}?)(ies|es|s)\b/iu', static function (array $m): string {
            return match (strtolower($m[2])) {
                'ies' => $m[1] . 'y',
                'es' => preg_match('/(s|x|z|ch|sh)$/i', $m[1]) ? $m[1] : $m[1] . 'e',
                default => str_ends_with(strtolower($m[1]), 's') ? $m[0] : $m[1],
            };
        }, trim($phrase));
    }
}
