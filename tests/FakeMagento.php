<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Magento\CustomerDataSource;
use Bitenxt\SupportAgent\Magento\MagentoAuthException;

/** In-memory Magento with two clinics, used to prove cross-customer isolation. */
final class FakeMagento implements CustomerDataSource
{
    /** @var array<string, array{customer: array, orders: list<array>}> keyed by token */
    public array $accounts = [];
    /** @var array<string, list<array>> follow-ups keyed by order number, NOT scoped (like the real resolver may be) */
    public array $followUps = [];
    public int $currentCustomerCalls = 0;

    public function __construct()
    {
        $this->accounts['token-clinic-a'] = [
            'customer' => ['customer_id' => '11', 'firstname' => 'Ana', 'email' => 'ana@clinic-a.test', 'business_name' => 'Clinic A'],
            'orders' => [[
                'number' => '000000101',
                'order_date' => '2026-09-20 10:00:00',
                'status' => 'processing',
                'status_title' => 'In design',
                'patient_name' => 'John Michael Smith',
                'total' => ['grand_total' => ['value' => 450, 'currency' => 'USD']],
                'items' => [['product_name' => 'Zirconia Crown', 'quantity_ordered' => 2, 'quantity_shipped' => 0]],
                'shipments' => [['tracking' => [['carrier' => 'ups', 'title' => 'UPS', 'number' => '1Z999AA10123456784']]]],
                // Fields that must never reach the model:
                'shipping_address' => ['street' => ['1 Secret St'], 'telephone' => '+1 555 0100 222'],
                'assigned_to' => 'designer-7',
            ]],
        ];
        $this->accounts['token-clinic-b'] = [
            'customer' => ['customer_id' => '22', 'firstname' => 'Bo', 'email' => 'bo@clinic-b.test', 'business_name' => 'Clinic B'],
            'orders' => [['number' => '000000202', 'status' => 'shipped', 'patient_name' => 'Mary Jones']],
        ];
        $this->followUps['000000101'] = [
            ['customer_id' => '11', 'sku' => 'ZC-1', 'note' => 'Please adjust the margin.', 'created_at' => '2026-09-21'],
            ['customer_id' => '22', 'sku' => 'X', 'note' => 'Row from another clinic', 'created_at' => '2026-09-22'],
        ];
        $this->followUps['000000202'] = [
            ['customer_id' => '22', 'sku' => 'AL-1', 'note' => 'Clinic B private note', 'created_at' => '2026-09-22'],
        ];
    }

    public function currentCustomer(string $token): array
    {
        $this->currentCustomerCalls++;
        if (!isset($this->accounts[$token])) {
            throw new MagentoAuthException('bad token');
        }

        return $this->accounts[$token]['customer'];
    }

    public function recentOrders(string $token, int $limit): array
    {
        return array_slice($this->accounts[$token]['orders'] ?? [], 0, $limit);
    }

    public function findOwnOrder(string $token, string $orderNumber): ?array
    {
        foreach ($this->accounts[$token]['orders'] ?? [] as $order) {
            if ($order['number'] === $orderNumber) {
                return $order;
            }
        }

        return null;
    }

    public function findOwnOrdersByPatient(string $token, string $patientName, int $limit): array
    {
        return array_values(array_filter(
            $this->accounts[$token]['orders'] ?? [],
            static fn ($o) => stripos($o['patient_name'] ?? '', $patientName) !== false,
        ));
    }

    public function orderFollowUps(string $token, string $orderNumber): array
    {
        return $this->followUps[$orderNumber] ?? [];
    }

    public function searchProducts(string $token, string $phrase, int $limit): array
    {
        return [['name' => 'Zirconia Crown', 'sku' => 'ZC-1', 'stock_status' => 'IN_STOCK',
            'price_range' => ['minimum_price' => ['final_price' => ['value' => 225, 'currency' => 'USD']]]]];
    }
}
