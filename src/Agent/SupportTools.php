<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Agent;

use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;
use Bitenxt\SupportAgent\Magento\CustomerDataSource;
use Bitenxt\SupportAgent\Magento\MagentoAuthException;
use Bitenxt\SupportAgent\Magento\MagentoException;
use Bitenxt\SupportAgent\Magento\OrderPresenter;
use Bitenxt\SupportAgent\Session\ChatSession;
use Bitenxt\SupportAgent\Support\Cache;
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

    /** The message being answered (not yet in the transcript), for hand-over emails. */
    public string $currentMessage = '';
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
        $orderNumber = ['type' => 'string', 'description' => 'The order number as the customer gave it, e.g. "000001234" or '
            . 'just "1234" (short numbers are matched automatically, so never ask for leading zeros).'];

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
                'name' => 'get_order_stats',
                'description' => "Count the signed-in customer's orders: the total, and how many are in each status. "
                    . 'Use for "how many orders do I have", "how many are processing/shipped".',
                'inputSchema' => $object([
                    'status' => ['type' => 'string', 'description' => 'A status to count, e.g. "processing", or an empty string for all.'],
                ], ['status']),
                'strict' => true,
            ],
            [
                'name' => 'list_patients',
                'description' => "List the patients on the signed-in customer's recent orders (short labels), with how many orders "
                    . 'each has and their latest order. Use for "list my patients" or to find a patient before looking up orders.',
                'inputSchema' => $object([
                    'name' => ['type' => 'string', 'description' => 'Part of a patient name to narrow the list, or an empty string for all.'],
                ], ['name']),
                'strict' => true,
            ],
            [
                'name' => 'get_available_coupons',
                'description' => 'List the coupons the signed-in customer can use now (name, code, discount, validity). '
                    . 'Use for any question about coupons, discounts, offers or promo codes.',
                'inputSchema' => $object([
                    'code' => ['type' => 'string', 'description' => 'A coupon code to check, or an empty string for all.'],
                ], ['code']),
                'strict' => true,
            ],
            [
                'name' => 'get_cart_summary',
                'description' => "Show the signed-in customer's current cart: items, total, applied coupon, patient and doctor "
                    . 'on the case, and scan upload status. Use for "what is in my cart", "is my scan uploaded", checkout questions.',
                'inputSchema' => $object([
                    'include_items' => ['type' => 'boolean', 'description' => 'Whether to list the items.'],
                ], ['include_items']),
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
                'description' => 'Search the product and service catalog by name or keyword. Returns name, SKU, price and stock. '
                    . 'If nothing matches, the result includes the catalog categories so you can suggest close alternatives.',
                'inputSchema' => $object([
                    'query' => ['type' => 'string', 'description' => 'Search words, e.g. "zirconia crown".'],
                ], ['query']),
                'strict' => true,
            ],
            [
                'name' => 'get_catalog_overview',
                'description' => 'List the product and service categories in the catalog, with example products in each. '
                    . 'Use when the customer asks what is available, what you offer, or browses without a specific product name.',
                'inputSchema' => $object([
                    'category' => ['type' => 'string', 'description' => 'A category to narrow the list, or an empty string for everything.'],
                ], ['category']),
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
                'get_order_stats' => $this->orderStats($input),
                'list_patients' => $this->patients($input),
                'get_available_coupons' => $this->coupons($input),
                'get_cart_summary' => $this->cart($input),
                'find_orders_by_patient' => $this->ordersByPatient($input),
                'get_order_follow_ups' => $this->followUps($input),
                'search_products' => $this->searchProducts($input),
                'get_catalog_overview' => $this->catalogOverview((string) ($input['category'] ?? '')),
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
            $result = ['error' => 'temporarily_unavailable', 'message' => 'That information is unavailable right now. Offer to try again later or to escalate.'];
        } catch (\Throwable $e) {
            // Unexpected data (e.g. a malformed row) must not crash the chat.
            $this->logger->log('tool_exception', ['session' => $this->session->id, 'tool' => $name,
                'class' => $e::class, 'detail' => mb_substr($e->getMessage(), 0, 500), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            $result = ['error' => 'temporarily_unavailable', 'message' => 'That information is unavailable right now. Offer to try again later or to escalate.'];
        }

        $isError = isset($result['error']);
        $this->logger->log('tool_call', ['session' => $this->session->id, 'tool' => $name, 'ok' => !$isError]);

        // Bad bytes in Magento text (e.g. a pasted patient name) must not break the reply.
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [is_string($json) ? $json : '{"error":"temporarily_unavailable"}', $isError];
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
    private function orderStats(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $stats = $this->magento->orderStats($this->customerToken);
        $byStatus = [];
        foreach ($stats['by_status'] as $status => $count) {
            $byStatus[ucfirst(str_replace('_', ' ', (string) $status))] = $count;
        }
        $wanted = mb_strtolower(trim((string) ($input['status'] ?? '')));
        if ($wanted !== '') {
            $byStatus = array_filter($byStatus, static fn ($k) => str_contains(mb_strtolower((string) $k), $wanted), ARRAY_FILTER_USE_KEY);
        }

        return array_filter([
            'total_orders' => $stats['total'],
            'by_status' => $byStatus,
            'note' => $stats['total'] > $stats['counted']
                ? "Status counts cover the latest {$stats['counted']} orders." : null,
        ], static fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $input */
    private function patients(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $filter = mb_strtolower(trim((string) ($input['name'] ?? '')));

        // The clinic's own patient list (the Pro "Patients" page), when available.
        $labels = [];
        foreach ($this->magento->ownPatients($this->customerToken, $this->session->customerId) as $patient) {
            if ($filter === '' || str_contains(mb_strtolower($patient['name']), $filter)) {
                $labels[] = OrderPresenter::patientLabel($patient['name']);
            }
        }
        $labels = array_values(array_unique(array_filter($labels)));
        if ($labels !== []) {
            return [
                'patients' => array_map(static fn ($l) => ['patient' => $l], array_slice($labels, 0, 50)),
                'note' => 'From the clinic\'s patient list. Patients are shown as first name and last initial. '
                    . 'Use find_orders_by_patient for a patient\'s orders.',
            ];
        }

        $patients = [];
        foreach ($this->magento->patientsFromOrders($this->customerToken, 100) as $order) {
            $name = trim((string) ($order['patient_name'] ?? ''));
            $label = OrderPresenter::patientLabel($name);
            if ($label === null || ($filter !== '' && !str_contains(mb_strtolower($name), $filter))) {
                continue;
            }
            $number = (string) ($order['number'] ?? '');
            $date = (string) ($order['order_date'] ?? '');
            $entry = $patients[$label] ?? ['patient' => $label, 'orders' => 0, 'latest_order' => '', 'latest_date' => ''];
            $entry['orders']++;
            if ($entry['latest_date'] === '' || strcmp($date, $entry['latest_date']) > 0) {
                [$entry['latest_order'], $entry['latest_date']] = [$number, $date];
            }
            $patients[$label] = $entry;
            if ($number !== '') {
                $this->session->rememberOrder($number);
                $this->session->addSafeValue($number);
            }
        }
        usort($patients, static fn ($a, $b) => strcmp($b['latest_date'], $a['latest_date']));

        return [
            'patients' => array_slice(array_values($patients), 0, 30),
            'note' => 'From the latest 100 orders. Patients are shown as first name and last initial.',
        ];
    }

    /** @param array<string, mixed> $input */
    private function coupons(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $coupons = array_values(array_filter(array_map(
            static fn (array $c) => OrderPresenter::coupon($c),
            $this->magento->availableCoupons($this->customerToken),
        )));
        $code = mb_strtolower(trim((string) ($input['code'] ?? '')));
        if ($code !== '') {
            $coupons = array_values(array_filter($coupons, static fn ($c) => mb_strtolower($c['code'] ?? '') === $code));
        }
        foreach ($coupons as $coupon) {
            if (isset($coupon['code'])) {
                $this->session->addSafeValue($coupon['code']);
            }
        }

        return $coupons === []
            ? ['coupons' => [], 'message' => $code !== '' ? 'That code is not an active coupon for this account.' : 'No active coupons right now.']
            : ['coupons' => $coupons, 'how_to_use' => 'Enter the code in the cart before checkout.'];
    }

    /** @param array<string, mixed> $input */
    private function cart(array $input): array
    {
        if ($error = $this->requireSignIn()) {
            return $error;
        }
        $cart = $this->magento->cartSummary($this->customerToken);
        if ($cart === null) {
            return ['cart' => null, 'message' => 'The cart is empty.'];
        }
        $view = OrderPresenter::cart($cart);
        if (($input['include_items'] ?? true) === false) {
            unset($view['items']);
        }
        foreach ($view['coupons_applied'] ?? [] as $code) {
            $this->session->addSafeValue($code);
        }

        return ['cart' => $view];
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

        if ($orders === []) {
            $known = array_filter(
                $this->magento->ownPatients($this->customerToken, $this->session->customerId),
                static fn (array $p) => str_contains(mb_strtolower($p['name']), mb_strtolower($name)),
            );
            if ($known !== []) {
                // The patient exists, but orders don't carry a patient name we can match.
                return ['orders' => [], 'count' => 0, 'patient_in_patient_list' => true,
                    'message' => 'This patient is in the clinic\'s patient list, but their orders cannot be matched by patient '
                        . 'in chat right now. Say so plainly (do not say the patient does not exist), suggest opening My Order '
                        . 'in the Pro portal, and offer an order number lookup or the support team.'];
            }
        }

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

        if ($products === []) {
            try {
                $overview = $this->catalogOverview();
            } catch (MagentoException $e) {
                // Auth errors still surface; a broken category list just leaves the suggestions out.
                if ($e instanceof MagentoAuthException) {
                    throw $e;
                }
                $this->logger->log('magento_error', ['session' => $this->session->id, 'tool' => 'catalog_overview', 'detail' => $e->getMessage()]);
                $overview = [];
            }

            return [
                'products' => [],
                'message' => 'No product matched that wording. Suggest the closest categories or products below, if any, '
                    . 'or ask the customer to describe what they need. Do not invent products.',
            ] + $overview;
        }

        return ['products' => $products];
    }

    private function catalogOverview(string $category = ''): array
    {
        // Same for every customer (names only), so cache it for an hour.
        $categories = Cache::get('catalog_overview');
        if (!is_array($categories)) {
            $categories = $this->magento->catalogOverview((string) $this->customerToken, 8);
            Cache::set('catalog_overview', $categories, 3600);
        }
        $category = mb_strtolower(trim($category));
        if ($category !== '') {
            $matching = array_values(array_filter(
                $categories,
                static fn (array $c) => str_contains(mb_strtolower($c['category']), $category),
            ));
            $categories = $matching !== [] ? $matching : $categories; // unknown name: show everything
        }

        return ['categories' => $categories];
    }

    /** @param array<string, mixed> $input */
    private function searchHelp(array $input): array
    {
        $query = (string) ($input['query'] ?? '');
        $articles = $this->knowledge->search($query);
        if ($articles === []) {
            // The weekly "what should we write next" list. Digits and emails are
            // masked so no order numbers or contact details end up in the log.
            $this->logger->log('knowledge_gap', ['session' => $this->session->id, 'query' => mb_substr((string) preg_replace(
                ['/\S+@\S+/', '/\d/'],
                ['[email]', '#'],
                $query,
            ), 0, 120)]);
        }

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

        $conversation = array_slice($this->session->transcript, -10);
        if ($this->currentMessage !== '') {
            $conversation[] = ['role' => 'user', 'text' => $this->currentMessage];
        }
        $sent = $this->handoff->notify([
            'session_id' => $this->session->id,
            'customer_id' => $this->session->customerId,
            'customer_name' => $this->session->customerFirstname,
            'customer_email' => $this->session->customerEmail,
            'conversation' => $conversation,
            'reason' => (string) ($input['reason'] ?? 'other'),
            'urgency' => ($input['urgency'] ?? '') === 'high' ? 'high' : 'normal',
            // Only pass on an order number we have confirmed belongs to this customer.
            'order_number' => in_array($orderNumber, $this->session->knownOrderNumbers, true) ? $orderNumber : '',
            'summary' => mb_substr((string) ($input['summary'] ?? ''), 0, 600),
        ]);
        if (!$sent) {
            // Don't promise an email that nobody will receive.
            return ['status' => 'not_delivered', 'message' => 'The request could not be sent to the team automatically. '
                . 'Apologise and give the customer the support phone number and email to contact the team directly.'];
        }
        $this->session->escalated = true;

        return ['status' => 'escalated', 'message' => 'Tell the customer the support team will follow up by email.'];
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
