<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

/**
 * Turns raw Magento data into the small, allow-listed views the model sees.
 * Anything not copied here (addresses, phone numbers, emails, payment data,
 * file paths, internal IDs, assigned designers, patient age/gender...) never
 * reaches the model, so it cannot leak it.
 */
final class OrderPresenter
{
    /** @param array<string, mixed> $order */
    public static function order(array $order): array
    {
        $tracking = [];
        foreach ($order['shipments'] ?? [] as $shipment) {
            foreach ($shipment['tracking'] ?? [] as $track) {
                $tracking[] = array_filter([
                    'carrier' => self::str($track['title'] ?? $track['carrier'] ?? null),
                    'tracking_number' => self::str($track['number'] ?? null),
                ]);
            }
        }

        $items = [];
        foreach (array_slice($order['items'] ?? [], 0, 20) as $item) {
            $items[] = array_filter([
                'product' => self::str($item['product_name'] ?? null),
                'qty_ordered' => self::num($item['quantity_ordered'] ?? null),
                'qty_shipped' => self::num($item['quantity_shipped'] ?? null),
            ], static fn ($v) => $v !== null);
        }

        $total = $order['total']['grand_total'] ?? null;

        return array_filter([
            'order_number' => self::str($order['number'] ?? null),
            'placed_on' => self::str($order['order_date'] ?? null),
            'status' => self::str($order['status_title'] ?? $order['status'] ?? null),
            'patient' => self::patientLabel($order['patient_name'] ?? null),
            'grand_total' => is_array($total) && isset($total['value'])
                ? number_format((float) $total['value'], 2) . ' ' . self::str($total['currency'] ?? '')
                : null,
            'shipping_method' => self::str($order['shipping_method'] ?? null),
            'items' => $items ?: null,
            'tracking' => $tracking ?: null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** Short form for order lists. @param array<string, mixed> $order */
    public static function orderSummary(array $order): array
    {
        return array_filter([
            'order_number' => self::str($order['number'] ?? null),
            'placed_on' => self::str($order['order_date'] ?? null),
            'status' => self::str($order['status_title'] ?? $order['order_status_title'] ?? $order['status'] ?? null),
            'patient' => self::patientLabel($order['patient_name'] ?? null),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $followUp */
    public static function followUp(array $followUp): array
    {
        return array_filter([
            'date' => self::str($followUp['created_at'] ?? null),
            'item_sku' => self::str($followUp['sku'] ?? null),
            'note' => self::truncate(self::str($followUp['note'] ?? null), 500),
        ]);
    }

    /**
     * A coupon the customer can use: name, code, discount and dates only.
     * Returns null for coupons that have already ended.
     *
     * @param array<string, mixed> $coupon
     */
    public static function coupon(array $coupon, ?int $today = null): ?array
    {
        $today ??= strtotime('today');
        $ends = self::str($coupon['to_date'] ?? null);
        if ($ends !== null && ($end = strtotime($ends)) !== false && $end < $today) {
            return null;
        }
        $amount = $coupon['discount_amount'] ?? null;
        $type = strtolower((string) ($coupon['discount_type'] ?? ''));
        $discount = is_numeric($amount)
            ? (str_contains($type, 'percent') || str_contains($type, 'by_percent')
                ? rtrim(rtrim(number_format((float) $amount, 2), '0'), '.') . '% off'
                : number_format((float) $amount, 2) . ' off')
            : null;

        $view = array_filter([
            'name' => self::str($coupon['name'] ?? null),
            'code' => self::str($coupon['code'] ?? $coupon['coupon_code'] ?? null),
            'details' => self::truncate(self::str($coupon['description'] ?? null), 200),
            'discount' => $discount,
            'valid_from' => self::str($coupon['from_date'] ?? null),
            'valid_until' => $ends,
        ], static fn ($v) => $v !== null && $v !== '');

        return isset($view['code']) || isset($view['name']) ? $view : null;
    }

    /**
     * The customer's own cart: items, total, coupon, patient label, doctor and
     * scan upload status. No file names (they can contain patient names).
     *
     * @param array<string, mixed> $cart
     */
    public static function cart(array $cart): array
    {
        $items = [];
        foreach (array_slice($cart['items'] ?? [], 0, 20) as $item) {
            if (is_array($item)) {
                $items[] = array_filter([
                    'product' => self::str($item['product']['name'] ?? null),
                    'qty' => self::num($item['quantity'] ?? null),
                ], static fn ($v) => $v !== null);
            }
        }
        $total = $cart['prices']['grand_total'] ?? null;

        $scan = null;
        if (is_array($cart['scan'] ?? null)) {
            $fileStatuses = [];
            foreach ($cart['scan']['files'] ?? [] as $file) {
                $state = is_array($file) ? (self::str($file['status'] ?? null) ?? 'unknown') : 'unknown';
                $fileStatuses[$state] = ($fileStatuses[$state] ?? 0) + 1;
            }
            $scan = array_filter([
                'status' => self::str($cart['scan']['status'] ?? null),
                'files' => count($cart['scan']['files'] ?? []),
                'file_statuses' => $fileStatuses ?: null,
            ], static fn ($v) => $v !== null);
        }

        return array_filter([
            'item_count' => self::num($cart['total_quantity'] ?? null) ?? count($items),
            'items' => $items ?: null,
            'total' => is_array($total) && isset($total['value'])
                ? number_format((float) $total['value'], 2) . ' ' . self::str($total['currency'] ?? '')
                : null,
            'coupons_applied' => array_values(array_filter(array_map(
                static fn ($c) => is_array($c) ? self::str($c['code'] ?? null) : null,
                $cart['applied_coupons'] ?? [],
            ))) ?: null,
            'patient' => self::patientLabel($cart['patient']['name'] ?? null),
            'doctor' => self::str($cart['doctor']['doctor_name'] ?? $cart['custom_shipping_attributes']['doctor_name'] ?? null),
            'scan_upload' => $scan,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $product */
    public static function product(array $product): array
    {
        $price = $product['price_range']['minimum_price']['final_price'] ?? null;

        return array_filter([
            'name' => self::str($product['name'] ?? null),
            'sku' => self::str($product['sku'] ?? null),
            'in_stock' => isset($product['stock_status']) ? $product['stock_status'] === 'IN_STOCK' : null,
            'price' => is_array($price) && isset($price['value'])
                ? number_format((float) $price['value'], 2) . ' ' . self::str($price['currency'] ?? '')
                : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Patient names are health data. The clinic already knows its own
     * patients, so a first name plus last initial is enough to tell orders
     * apart without putting full names into the conversation.
     */
    public static function patientLabel(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.';
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : $value;
    }

    private static function num(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    private static function truncate(?string $value, int $max): ?string
    {
        return $value !== null && mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }
}
