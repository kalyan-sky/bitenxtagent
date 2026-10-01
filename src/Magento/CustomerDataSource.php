<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

/**
 * Everything the chatbot is allowed to read from Magento. Every method is
 * scoped to the customer who owns $token; there is deliberately no method that
 * takes a customer ID, email or any other identity chosen by the caller.
 */
interface CustomerDataSource
{
    /** @return array{customer_id: string, firstname: string, email: string, business_name: string} */
    public function currentCustomer(string $token): array;

    /** @return list<array<string, mixed>> the customer's own most recent orders (raw Magento shape) */
    public function recentOrders(string $token, int $limit): array;

    /** @return array<string, mixed>|null the order if, and only if, it belongs to the token's customer */
    public function findOwnOrder(string $token, string $orderNumber): ?array;

    /** @return list<array<string, mixed>> the customer's own orders whose patient name matches */
    public function findOwnOrdersByPatient(string $token, string $patientName, int $limit): array;

    /** @return list<array<string, mixed>> follow-ups on an order (caller must have checked ownership) */
    public function orderFollowUps(string $token, string $orderNumber): array;

    /** @return list<array<string, mixed>> catalog products matching a search phrase */
    public function searchProducts(string $token, string $phrase, int $limit): array;
}
