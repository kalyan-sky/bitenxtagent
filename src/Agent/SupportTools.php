<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Magento\CustomerDataSource;
use Bitenxt\SupportAgent\Magento\MagentoAuthException;
use Bitenxt\SupportAgent\Magento\MagentoException;
use Bitenxt\SupportAgent\Magento\OrderPresenter;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Support\HandoffNotifier;
use Bitenxt\SupportAgent\Support\Logger;

/**
 * The only actions the model can take. All of them are read-only except
 * escalate_to_human. None accepts a customer ID, email or token: identity
 * always comes from the verified session, so a prompt-injected model still
 * cannot reach another customer's data.
 */
final class SupportTools
{
    public const MAX_FAILED_ORDER_LOOKUPS = 5;
    private const ORDER_NUMBER = '/^[A-Za-z0-9-]{3,32}$/';

    public function __construct(
        private readonly ChatSession $session,
        private readonly ?string $customerToken,
        private readonly CustomerDataSource $magento,
        private readonly KnowledgeBase $knowledge,
        private readonly HandoffNotifier $handoff,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<array<string, mixed>> tool definitions for the Messages API */
    public static function definitions(): array
    {
        $object = static fn (array $properties, array $required = []) => [
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
        $orderNumber = ['type' => 'string', 'description' => 'The order number exactly as the customer gave it, e.g. "000001234".'];

        return [
            [
                'name' => 'get_recent_orders',
                'description' => "List the signed-in customer's most recent orders (number, date, status, patient label). "
                    . 'Use when the customer asks about "my order" without a number, or wants an overview.',
                'inputSchema' => $object([
                    'limit' => ['type' => 'integer', 'description' => 'How many orders to return, 1-10.'],
                ], ['limit']),
                'strict' => true,
            ],
            [
                'name' => 'get_order_status',
                'description' => "Get status, items, total, shipping method and tracking for one of the signed-in customer's own orders. "
                    . 'Returns not_found if the number is not on this account.',
                'inputSchema' => $object(['order_number' => $orderNumber], ['order_number']),
                'strict' => true,
            ],
            [
                'name' => 'find_orders_by_patient',
                'description' => "Find the signed-in customer's own orders for a patient, by the patient name the customer typed.",
                'inputSchema' => $object([
                    'patient_name' => ['type' => 'string', 'description' => 'Patient name as typed by the customer.'],
                ], ['patient_name']),
                'strict' => true,
            ],
            [
                'name' => 'get_order_follow_ups',
                'description' => "List notes and follow-ups recorded on one of the signed-in customer's own orders.",
                'inputSchema' => $object(['order_number' => $orderNumber], ['order_number']),
                'strict' => true,
            ],
            [
                'name' => 'search_products',
                'description' => 'Search the product and service catalog by name or keyword. Returns name, SKU, price and stock.',
                'inputSchema' => $object([
                    'query' => ['type' => 'string', 'description' => 'Search words, e.g. "zirconia crown".'],
                ], ['query']),
                'strict' => true,
            ],
            [
                'name' => 'search_help_articles',
                'description' => 'Search the help center (shipping, turnaround times, scans and file uploads, remakes, returns, '
                    . 'payments, account). Use before answering any policy or how-to question.',
                'inputSchema' => $object([
                    'query' => ['type' => 'string', 'description' => 'What the customer wants to know.'],
                ], ['query']),
                'strict' => true,
            ],
            [
                'name' => 'escalate_to_human',
                'description' => 'Hand the conversation to the support team. Use when the customer asks for a person, when a '
                    . 'request needs a change you cannot make (cancel, edit, refund, remake, address change), when the '
                    . 'customer is upset, or when you cannot resolve the issue with the other tools.',
                'inputSchema' => $object([
                    'reason' => ['type' => 'string', 'enum' => [
                        'customer_requested', 'order_change', 'refund_or_billing', 'remake_or_quality',
                        'delivery_problem', 'technical_issue', 'other',
                    ]],
                    'summary' => ['type' => 'string', 'description' => 'One or two sentences for the support agent. No patient health details.'],
                    'order_number' => ['type' => 'string', 'description' => 'Related order number, or an empty string.'],
                    'urgency' => ['type' => 'string', 'enum' => ['normal', 'high']],
                ], ['reason', 'summary', 'order_number', 'urgency']),
                'strict' => true,
            ],
        ];
    }

    /**
     * Runs one tool call and returns [JSON result for the model, isError].
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: bool}
     */
    public function execute(string $name, array $input): array
    {
        try {
            $result = match ($name) {
                'get_recent_orders' => $this->recentOrders($input),
                'get_order_status' => $this->orderStatus($input),
                'find_orders_by_patient' => $this->ordersByPatient($input),
                'get_order_follow_ups' => $this->followUps($input),
                'search_products' => $this->searchProducts($input),
                'search_help_articles' => $this->searchHelp($input),
                'escalate_to_human' => $this->escalate($input),
                default => ['error' => 'unknown_tool'],
            };
        } catch (MagentoAuthException $e) {
            $this->logger->log('magento_auth_error', ['session' => $this->session->id, 'tool' => $name, 'detail' => $e->getMessage()]);
            $result = ['error' => 'session_expired', 'message' => 'The customer needs to sign in again to see account details.'];
        } catch (MagentoException $e) {
            // The real error stays in our log; the model only learns that the
            // lookup failed, so it cannot repeat internals to the customer.
            $this->logger->log('magento_error', ['session' => $this->session->id, 'tool' => $name, 'detail' => $e->getMessage()]);
            $result = ['error' => 'temporarily_unavailable', 'message' => 'Order information is unavailable right now. Offer to try again later or to escalate.'];
        }

        $isError = isset($result['error']);
        $this->logger->log('tool_call', ['session' => $this->session->id, 'tool' => $name, 'ok' => !$isError]);

        return [json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $isError];
    }

    /** @param array<string, mixed> $input */
    private function recentOrders(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $limit = max(1, min(10, (int) ($input['limit'] ?? 5)));
        $orders = array_map(function (array $order) {
            $view = OrderPresenter::orderSummary($order);
            $this->trust($view);

            return $view;
        }, $this->magento->recentOrders($this->customerToken, $limit));

        return ['orders' => $orders, 'count' => count($orders)];
    }

    /** @param array<string, mixed> $input */
    private function orderStatus(array $input): array
    {
        if ($error = $this->requireSignIn() ?? $this->checkOrderNumber($input['order_number'] ?? null)) {
            return $error;
        }
        $orderNumber = trim((string) $input['order_number']);
        $order = $this->magento->findOwnOrder($this->customerToken, $orderNumber);
        if ($order === null) {
            return $this->notFound($orderNumber);
        }

        $view = OrderPresenter::order($order);
        $this->trust($view);

        return ['order' => $view];
    }

    /** @param array<string, mixed> $input */
    private function ordersByPatient(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $name = trim((string) ($input['patient_name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return ['error' => 'invalid_input', 'message' => 'Ask the customer for the patient name.'];
        }
        $orders = array_map(function (array $order) {
            $view = OrderPresenter::orderSummary($order);
            $this->trust($view);

            return $view;
        }, $this->magento->findOwnOrdersByPatient($this->customerToken, $name, 10));

        return ['orders' => $orders, 'count' => count($orders)];
    }

    /** @param array<string, mixed> $input */
    private function followUps(array $input): array
    {
        if ($error = $this->requireSignIn() ?? $this->checkOrderNumber($input['order_number'] ?? null)) {
            return $error;
        }
        $orderNumber = trim((string) $input['order_number']);

        // getOrderFollowUps takes a bare order number and may not check who is
        // asking, so confirm ownership through the customer-scoped query first.
        if (!in_array($orderNumber, $this->session->knownOrderNumbers, true)) {
            $order = $this->magento->findOwnOrder($this->customerToken, $orderNumber);
            if ($order === null) {
                return $this->notFound($orderNumber);
            }
            $orderNumber = (string) ($order['number'] ?? $orderNumber); // e.g. "726" → "000000726"
        }
        $this->session->rememberOrder($orderNumber);

        $items = [];
        foreach ($this->magento->orderFollowUps($this->customerToken, $orderNumber) as $followUp) {
            // Second check: drop any row that is not this customer's.
            if ($this->session->customerId !== '' && isset($followUp['customer_id'])
                && (string) $followUp['customer_id'] !== $this->session->customerId) {
                $this->logger->log('foreign_row_dropped', ['session' => $this->session->id, 'tool' => 'get_order_follow_ups']);
                continue;
            }
            $items[] = OrderPresenter::followUp($followUp);
            if (!empty($followUp['sku'])) {
                $this->session->addSafeValue((string) $followUp['sku']);
            }
        }

        return ['order_number' => $orderNumber, 'follow_ups' => $items];
    }

    /** @param array<string, mixed> $input */
    private function searchProducts(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return ['error' => 'invalid_input', 'message' => 'Ask the customer what product they are looking for.'];
        }
        $products = array_map(function (array $product) {
            $view = OrderPresenter::product($product);
            if (isset($view['sku'])) {
                $this->session->addSafeValue($view['sku']);
            }

            return $view;
        }, $this->magento->searchProducts((string) $this->customerToken, $query, 5));

        return ['products' => $products];
    }

    /** @param array<string, mixed> $input */
    private function searchHelp(array $input): array
    {
        $articles = $this->knowledge->search((string) ($input['query'] ?? ''));

        return $articles === []
            ? ['articles' => [], 'message' => 'No help article matches. Do not guess a policy; offer to escalate.']
            : ['articles' => $articles];
    }

    /** @param array<string, mixed> $input */
    private function escalate(array $input): array
    {
        if ($this->session->escalated) {
            return ['status' => 'already_escalated', 'message' => 'The support team already has this conversation.'];
        }
        $orderNumber = trim((string) ($input['order_number'] ?? ''));

        $sent = $this->handoff->notify([
            'session_id' => $this->session->id,
            'customer_id' => $this->session->customerId,
            'customer_email' => $this->session->customerEmail,
            'reason' => (string) ($input['reason'] ?? 'other'),
            'urgency' => ($input['urgency'] ?? '') === 'high' ? 'high' : 'normal',
            // Only pass on an order number we have confirmed belongs to this customer.
            'order_number' => in_array($orderNumber, $this->session->knownOrderNumbers, true) ? $orderNumber : '',
            'summary' => mb_substr((string) ($input['summary'] ?? ''), 0, 600),
        ]);
        $this->session->escalated = true;

        return ['status' => $sent ? 'escalated' : 'queued', 'message' => 'Tell the customer the support team will follow up by email.'];
    }

    /** @return array<string, string>|null */
    private function requireSignIn(): ?array
    {
        if ($this->customerToken === null || !$this->session->isAuthenticated()) {
            return [
                'error' => 'not_signed_in',
                'message' => 'Order details are only available when the customer is signed in to their BiteNXT account. Ask them to sign in.',
            ];
        }

        return null;
    }

    /** @return array<string, string>|null */
    private function checkOrderNumber(mixed $orderNumber): ?array
    {
        if ($this->session->failedOrderLookups >= self::MAX_FAILED_ORDER_LOOKUPS) {
            return ['error' => 'too_many_attempts', 'message' => 'Too many order numbers were not found. Offer to escalate to the support team instead.'];
        }
        if (!is_string($orderNumber) || !preg_match(self::ORDER_NUMBER, trim($orderNumber))) {
            return ['error' => 'invalid_order_number', 'message' => 'Ask the customer to check the order number (letters, digits and dashes only).'];
        }

        return null;
    }

    /** @return array<string, string> */
    private function notFound(string $orderNumber): array
    {
        $this->session->failedOrderLookups++;
        $this->logger->log('order_not_found', ['session' => $this->session->id, 'order' => Logger::pseudonym($orderNumber)]);

        // Same answer whether the order does not exist or belongs to someone
        // else, so the bot cannot be used to probe other customers' orders.
        return ['error' => 'not_found', 'message' => 'No order with that number was found on this account.'];
    }

    /** Remember identifiers from the customer's own data so OutputGuard lets them through. */
    private function trust(array $view): void
    {
        if (isset($view['order_number'])) {
            $this->session->rememberOrder($view['order_number']);
            $this->session->addSafeValue($view['order_number']);
        }
        foreach ($view['tracking'] ?? [] as $track) {
            if (isset($track['tracking_number'])) {
                $this->session->addSafeValue($track['tracking_number']);
            }
        }
    }
}
