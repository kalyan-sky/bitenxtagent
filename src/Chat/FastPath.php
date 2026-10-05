<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Chat;

use Bitenxt\SupportAgent\Agent\SupportTools;

/**
 * Answers the most common questions without calling the AI: "status of order
 * 671", "where is my order 000000725", a bare order number, or "my orders".
 * The reply is a fixed template filled from the same tools (and the same
 * ownership checks) the AI would use, so it is instant and costs no tokens.
 *
 * Anything that needs judgement (cancel, change, why, how, several requests in
 * one message...) returns null and goes to the AI as before.
 */
final class FastPath
{
    private const MAX_LENGTH = 100;
    private const ORDER_NUMBER = '/(?<![\w-])#?(\d{3,12})(?![\w-])/';
    private const ORDER_WORDS = '/\b(status|track|tracking|where|order|update|shipped|dispatched|delivered)\b/i';
    private const NEEDS_AI = '/\b(cancel|change|edit|modify|refund|remake|return|complain|complaint|wrong|damaged|why|how|when|'
        . 'note|notes|follow|invoice|address|reorder|again|patient|help|delay|delayed|late|person|agent|human|support|'
        . 'and|also|not|problem|issue|urgent)\b/i';
    private const RECENT_ORDERS = '/^\s*(show|list|see|view|check|get)?\s*(me\s+)?(all\s+)?(my\s+)?(recent|latest|last|past|previous)?\s*'
        . 'orders?(\s+(history|list|status))?\s*[?.!]*\s*$/i';

    private const ORDER_COUNT = '/^\s*(how\s+many|total|count|number\s+of)\b.*\borders?\b[^.]*[?.!]*\s*$/i';
    private const COUPONS = '/^\s*(are\s+there\s+|do\s+i\s+have\s+|any\s+|show\s+(me\s+)?|list\s+|my\s+|available\s+|what\s+)*'
        . '(active\s+|available\s+)?(coupons?|coupon\s+codes?|promo\s+codes?|discount\s+codes?|offers?|discounts?)'
        . '(\s+(available|for\s+me|do\s+i\s+have|are\s+there|now|today))*\s*[?.!]*\s*$/i';
    private const CART = '/^\s*(show\s+(me\s+)?|what\'?s\s+in\s+|what\s+is\s+in\s+|view\s+|check\s+)?(my\s+)?cart\s*[?.!]*\s*$/i';

    /** @return string|null the reply, or null when the AI should handle the message */
    public function answer(string $text, SupportTools $tools): ?string
    {
        $text = trim($text);
        // "how many" is a count, not a how-to question.
        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH
            || preg_match(self::NEEDS_AI, (string) preg_replace('/\bhow\s+many\b/i', '', $text))) {
            return null;
        }

        if (preg_match(self::RECENT_ORDERS, $text)) {
            return $this->recentOrders($tools);
        }
        if (preg_match(self::ORDER_COUNT, $text)) {
            return $this->orderCount($tools);
        }
        if (preg_match(self::COUPONS, $text)) {
            return $this->coupons($tools);
        }
        if (preg_match(self::CART, $text)) {
            return $this->cart($tools);
        }

        preg_match_all(self::ORDER_NUMBER, $text, $numbers);
        if (count($numbers[1]) !== 1) {
            return null; // no number, or several: let the AI sort it out
        }
        $isBareNumber = preg_match('/^\s*#?\d{3,12}\s*[?.!]*\s*$/', $text) === 1;
        if (!$isBareNumber && !preg_match(self::ORDER_WORDS, $text)) {
            return null;
        }

        return $this->orderStatus($numbers[1][0], $tools);
    }

    private function orderStatus(string $number, SupportTools $tools): ?string
    {
        $result = self::run($tools, 'get_order_status', ['order_number' => $number]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], $number);
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $lines = [sprintf('Order %s: %s', $order['order_number'] ?? $number, self::status($order['status'] ?? 'Unknown'))];
        $placed = array_filter([
            isset($order['placed_on']) ? 'Placed on ' . self::date($order['placed_on']) : null,
            isset($order['patient']) ? 'Patient: ' . $order['patient'] : null,
        ]);
        if ($placed !== []) {
            $lines[] = implode(' · ', $placed);
        }
        if (!empty($order['items'])) {
            $lines[] = 'Items: ' . implode(', ', array_map(
                static fn (array $item) => ($item['product'] ?? 'Item') . (isset($item['qty_ordered']) ? ' × ' . $item['qty_ordered'] : ''),
                $order['items'],
            ));
        }
        $money = array_filter([
            isset($order['grand_total']) ? 'Total: ' . $order['grand_total'] : null,
            isset($order['shipping_method']) ? 'Shipping: ' . $order['shipping_method'] : null,
        ]);
        if ($money !== []) {
            $lines[] = implode(' · ', $money);
        }
        foreach ($order['tracking'] ?? [] as $track) {
            if (isset($track['tracking_number'])) {
                $lines[] = 'Tracking: ' . trim(($track['carrier'] ?? '') . ' ' . $track['tracking_number']);
            }
        }
        $lines[] = '';
        $lines[] = 'Anything else about this order? I can show its follow-up notes or connect you with our team.';

        return implode("\n", $lines);
    }

    private function recentOrders(SupportTools $tools): ?string
    {
        $result = self::run($tools, 'get_recent_orders', ['limit' => 5]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $orders = $result['orders'] ?? [];
        if ($orders === []) {
            return "I couldn't find any orders on your account yet.";
        }

        $lines = ['Your recent orders:'];
        foreach ($orders as $order) {
            $lines[] = '• ' . implode(' · ', array_filter([
                $order['order_number'] ?? null,
                isset($order['status']) ? self::status($order['status']) : null,
                isset($order['placed_on']) ? self::date($order['placed_on']) : null,
                $order['patient'] ?? null,
            ]));
        }
        $lines[] = '';
        $lines[] = 'Send me an order number for its full details.';

        return implode("\n", $lines);
    }

    private function orderCount(SupportTools $tools): ?string
    {
        $result = self::run($tools, 'get_order_stats', ['status' => '']);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $total = (int) ($result['total_orders'] ?? 0);
        if ($total === 0) {
            return "You don't have any orders yet.";
        }
        $lines = [sprintf('You have %d order%s in total.', $total, $total === 1 ? '' : 's')];
        foreach ($result['by_status'] ?? [] as $status => $count) {
            $lines[] = "• {$status}: {$count}";
        }
        if (isset($result['note'])) {
            $lines[] = '(' . $result['note'] . ')';
        }
        $lines[] = '';
        $lines[] = 'Say "my orders" to see the latest ones, or send an order number for details.';

        return implode("\n", $lines);
    }

    private function coupons(SupportTools $tools): ?string
    {
        $result = self::run($tools, 'get_available_coupons', ['code' => '']);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        if (($result['coupons'] ?? []) === []) {
            return 'There are no active coupons on your account right now.';
        }
        $lines = ['Coupons you can use:'];
        foreach ($result['coupons'] as $coupon) {
            $lines[] = '• ' . implode(' · ', array_filter([
                isset($coupon['code']) ? 'Code ' . $coupon['code'] : null,
                $coupon['name'] ?? null,
                $coupon['discount'] ?? null,
                isset($coupon['valid_until']) ? 'valid until ' . self::date($coupon['valid_until']) : null,
            ]));
        }
        $lines[] = '';
        $lines[] = 'Enter the code in your cart before checkout.';

        return implode("\n", $lines);
    }

    private function cart(SupportTools $tools): ?string
    {
        $result = self::run($tools, 'get_cart_summary', ['include_items' => true]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $cart = $result['cart'] ?? null;
        if (!is_array($cart)) {
            return 'Your cart is empty.';
        }
        $lines = ['Your cart:'];
        foreach ($cart['items'] ?? [] as $item) {
            $lines[] = '• ' . ($item['product'] ?? 'Item') . (isset($item['qty']) ? ' × ' . $item['qty'] : '');
        }
        $details = array_filter([
            isset($cart['total']) ? 'Total: ' . $cart['total'] : null,
            isset($cart['coupons_applied']) ? 'Coupon: ' . implode(', ', $cart['coupons_applied']) : null,
        ]);
        if ($details !== []) {
            $lines[] = implode(' · ', $details);
        }
        $case = array_filter([
            isset($cart['patient']) ? 'Patient: ' . $cart['patient'] : null,
            isset($cart['doctor']) ? 'Doctor: ' . $cart['doctor'] : null,
        ]);
        if ($case !== []) {
            $lines[] = implode(' · ', $case);
        }
        if (isset($cart['scan_upload'])) {
            $scan = $cart['scan_upload'];
            $lines[] = 'Scan upload: ' . trim(ucfirst((string) ($scan['status'] ?? '')) . ' (' . ($scan['files'] ?? 0) . ' file'
                . (($scan['files'] ?? 0) === 1 ? '' : 's') . ')');
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    private static function run(SupportTools $tools, string $tool, array $input): array
    {
        [$json] = $tools->execute($tool, $input);

        return json_decode($json, true) ?: ['error' => 'invalid'];
    }

    private static function errorReply(string $error, string $number): ?string
    {
        return match ($error) {
            'not_found' => "I couldn't find order {$number} on your account. Please check the number and try again.",
            'too_many_attempts' => "I couldn't find those orders on your account. If you need help, ask me to connect you with our support team.",
            'session_expired', 'not_signed_in' => 'Your login has expired. Please sign in to BiteNXT Pro again.',
            'temporarily_unavailable' => "I can't load order details right now. Please try again in a few minutes.",
            default => null, // unexpected: let the AI handle it
        };
    }

    private static function status(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private static function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('j M Y', $time);
    }
}
