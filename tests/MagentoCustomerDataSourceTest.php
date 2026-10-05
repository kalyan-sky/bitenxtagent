<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Tests;

use Bitenxt\SupportAgent\Magento\GraphQLClient;
use Bitenxt\SupportAgent\Magento\MagentoAuthException;
use Bitenxt\SupportAgent\Magento\MagentoCustomerDataSource;
use Bitenxt\SupportAgent\Magento\MagentoException;
use Bitenxt\SupportAgent\Magento\OrderPresenter;
use Bitenxt\SupportAgent\Support\Logger;
use PHPUnit\Framework\TestCase;

/** Replays real Magento GraphQL responses through the real client and data source. */
final class MagentoCustomerDataSourceTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir() . '/bnx-magento-' . bin2hex(random_bytes(4)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    /** @param callable(array $request): array|string $responder returns the response body */
    private function source(callable $responder, ?array &$requests = null): MagentoCustomerDataSource
    {
        $requests = [];
        $client = new class ('https://magento.test/graphql', 5, $responder, $requests) extends GraphQLClient {
            public function __construct(string $endpoint, int $timeout, private $responder, private array &$requests)
            {
                parent::__construct($endpoint, $timeout);
            }

            protected function send(string $body, array $headers): array
            {
                $request = json_decode($body, true);
                $this->requests[] = $request;
                $response = ($this->responder)($request);

                return [200, is_string($response) ? $response : json_encode($response)];
            }
        };

        return new MagentoCustomerDataSource($client, new Logger($this->log));
    }

    /** The exact error shape seen on UAT for order 000000726. */
    private static function partialResponse(string $number): array
    {
        return [
            'errors' => [
                ['message' => 'Internal server error', 'path' => ['customer', 'orders', 'items', 0, 'status_title'],
                    'extensions' => ['debugMessage' => "The entity that was requested doesn't exist. Verify the entity and try again."]],
                ['message' => 'Internal server error', 'path' => ['customer', 'orders', 'items', 0, 'patient_name'],
                    'extensions' => ['debugMessage' => "The entity that was requested doesn't exist. Verify the entity and try again."]],
            ],
            'data' => ['customer' => ['orders' => ['items' => [[
                'number' => $number, 'order_date' => '2026-09-30 10:00:00', 'status' => 'processing',
                'status_title' => null, 'patient_name' => null, 'carrier' => null, 'shipping_method' => 'Flat Rate',
                'total' => ['grand_total' => ['value' => 120, 'currency' => 'USD']],
                'items' => [['product_name' => 'Zirconia Crown', 'quantity_ordered' => 1, 'quantity_shipped' => 0]],
                'shipments' => [],
            ]]]]],
        ];
    }

    public function testOrderIsReturnedWhenOnlyCustomFieldsFail(): void
    {
        $order = $this->source(fn () => self::partialResponse('000000726'))->findOwnOrder('tok', '000000726');

        self::assertNotNull($order);
        $view = OrderPresenter::order($order);
        self::assertSame('processing', $view['status'], 'falls back to the core status when status_title fails');
        self::assertArrayNotHasKey('patient', $view);
        self::assertSame('Zirconia Crown', $view['items'][0]['product']);
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('magento_partial', $log);
        self::assertStringContainsString('customer.orders.items.status_title', $log);
    }

    public function testShortOrderNumberIsZeroPadded(): void
    {
        $order = $this->source(function (array $request) {
            return $request['variables']['number'] === '000000726'
                ? self::partialResponse('000000726')
                : ['data' => ['customer' => ['orders' => ['items' => []]]]];
        }, $requests)->findOwnOrder('tok', '726');

        self::assertSame('000000726', $order['number']);
        self::assertSame(['726', '000000726'], array_column(array_column($requests, 'variables'), 'number'));
    }

    public function testFallsBackToCoreFieldsWhenTheQueryIsRejected(): void
    {
        $order = $this->source(function (array $request) {
            if (str_contains($request['query'], 'status_title')) {
                return ['errors' => [['message' => 'Cannot query field "status_title" on type "CustomerOrder".']]];
            }

            return ['data' => ['customer' => ['orders' => ['items' => [['number' => '000000101', 'status' => 'complete']]]]]];
        }, $requests)->findOwnOrder('tok', '000000101');

        self::assertSame('complete', $order['status']);
        self::assertCount(2, $requests);
        self::assertStringNotContainsString('patient_name', $requests[1]['query']);
        self::assertStringContainsString('magento_order_fields_fallback', (string) file_get_contents($this->log));
    }

    public function testOtherCustomersOrderIsStillNotFound(): void
    {
        $order = $this->source(fn () => ['data' => ['customer' => ['orders' => ['items' => []]]]])->findOwnOrder('tok', '000000202');
        self::assertNull($order);
    }

    public function testAuthErrorsAreNeverTreatedAsPartial(): void
    {
        $this->expectException(MagentoAuthException::class);
        $this->source(fn () => [
            'errors' => [['message' => 'not authorized', 'extensions' => ['category' => 'graphql-authorization']]],
            'data' => ['customer' => null],
        ])->findOwnOrder('tok', '000000101');
    }

    public function testErrorsWithNoDataStillFail(): void
    {
        $this->expectException(MagentoException::class);
        $this->source(fn () => ['errors' => [['message' => 'boom']], 'data' => null])->findOwnOrder('tok', '000000101');
    }

    public function testProductSearchRetriesSingularThenNameMatch(): void
    {
        $products = $this->source(function (array $request) {
            if (str_contains($request['query'], 'match') && $request['variables']['q'] === 'Aligner') {
                return ['data' => ['products' => ['items' => [['name' => 'Clear Aligner Design', 'sku' => 'AL-D']]]]];
            }

            return ['data' => ['products' => ['items' => []]]];
        }, $requests)->searchProducts('tok', 'Aligners', 5);

        self::assertSame('Clear Aligner Design', $products[0]['name']);
        self::assertSame(['Aligners', 'Aligner', 'Aligner'], array_map(fn ($r) => $r['variables']['q'], $requests));
    }

    public function testCatalogOverviewListsCategoriesWithProducts(): void
    {
        $products = fn (array $names) => ['total_count' => count($names), 'items' => array_map(fn ($n) => ['name' => $n], $names)];
        $overview = $this->source(fn () => ['data' => ['categoryList' => [[
            'name' => 'Default Category',
            'children' => [
                ['name' => 'Crowns', 'include_in_menu' => 1, 'products' => $products(['Zirconia Crown']), 'children' => [
                    ['name' => 'Anterior', 'include_in_menu' => 1, 'products' => $products(['E.max Crown'])],
                ]],
                ['name' => 'Empty', 'include_in_menu' => 1, 'products' => $products([]), 'children' => []],
                ['name' => 'Hidden', 'include_in_menu' => 0, 'products' => $products(['Internal']), 'children' => []],
            ],
        ]]]])->catalogOverview('tok', 8);

        self::assertSame(['Crowns', 'Crowns / Anterior'], array_column($overview, 'category'));
        self::assertSame(['Zirconia Crown'], $overview[0]['products']);
    }

    public function testUnknownCouponFieldsAreDroppedAndRetried(): void
    {
        $coupons = $this->source(function (array $request) {
            if (str_contains($request['query'], 'discount_type')) {
                return ['errors' => [
                    ['message' => 'Cannot query field "discount_type" on type "Coupon".'],
                    ['message' => 'Cannot query field "description" on type "Coupon".'],
                ]];
            }

            return ['data' => ['availableCoupons' => [['name' => 'Festive', 'code' => 'FEST10', 'discount_amount' => 10]]]];
        }, $requests)->availableCoupons('tok');

        self::assertSame('FEST10', $coupons[0]['code']);
        self::assertCount(2, $requests);
        self::assertStringNotContainsString('description', $requests[1]['query']);
    }

    public function testCartDetailsUseOnlyTheCustomersOwnCartId(): void
    {
        $cart = $this->source(function (array $request) {
            if (str_contains($request['query'], 'customerCart')) {
                return ['data' => ['customerCart' => ['id' => 'own-cart', 'total_quantity' => 1, 'items' => []]]];
            }
            if (str_contains($request['query'], 'kixrScanStatus')) {
                return ['errors' => [['message' => 'boom']], 'data' => null];
            }
            self::assertSame('own-cart', $request['variables']['id']);

            return ['data' => ['getPatientFromCart' => ['quote_id' => '962', 'patient_id' => 312, 'patient_name' => 'Asha Verma'],
                'getDoctorFromCart' => ['doctor_name' => 'Dr. Rao']]];
        })->cartSummary('tok');

        self::assertSame('Asha V.', OrderPresenter::cart($cart)['patient']);
        self::assertNull($cart['scan'], 'a failing detail is left out, not fatal');
        self::assertStringContainsString('magento_cart_detail_unavailable', (string) file_get_contents($this->log));
    }

    public function testPatientSearchFallsBackToScanningOrdersForPartOfTheName(): void
    {
        $orders = $this->source(function (array $request) {
            if (str_contains($request['query'], 'filter: { patient_name')) {
                return ['data' => ['customerAllOrders' => ['items' => []]]]; // exact-match search finds nothing
            }
            if (str_contains($request['query'], 'customerAllOrders')) {
                return ['data' => ['customerAllOrders' => ['items' => [
                    ['number' => '000000700', 'order_date' => '2026-09-01', 'patient_name' => 'D Narendra Kumar'],
                    ['number' => '000000720', 'order_date' => '2026-09-20', 'patient_name' => 'Narendra K'],
                    ['number' => '000000710', 'order_date' => '2026-09-10', 'patient_name' => 'Someone Else'],
                ]]]];
            }

            return ['data' => ['customer' => ['orders' => ['items' => []]]]];
        }, $requests)->findOwnOrdersByPatient('tok', 'narendra', 10);

        self::assertSame(['000000720', '000000700'], array_column($orders, 'number'), 'newest first, any case, part of the name');
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('"strategy":"scan_customerAllOrders"', $log);
        self::assertStringNotContainsString('Narendra', $log, 'no patient names in logs');
    }

    public function testPatientSearchLogsWhenMagentoReturnsNoPatientNames(): void
    {
        $orders = $this->source(fn (array $request) => str_contains($request['query'], 'customer {')
            ? ['data' => ['customer' => ['orders' => ['items' => [['number' => '1', 'patient_name' => null]]]]],
                'errors' => [['message' => 'Internal server error', 'path' => ['customer', 'orders', 'items', 0, 'patient_name']]]]
            : ['data' => ['customerAllOrders' => ['items' => []]]])->findOwnOrdersByPatient('tok', 'kalyan', 10);

        self::assertSame([], $orders);
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('"orders_with_patient_name":0', $log);
        self::assertStringContainsString('customer.orders.items.patient_name', $log);
    }
}
