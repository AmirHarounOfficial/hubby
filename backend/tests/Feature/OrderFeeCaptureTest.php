<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Integrations\Contracts\CapturesOrderFees;
use App\Services\Profit\CostResolver;
use App\Services\Profit\FifoLedger;
use App\Services\Profit\OrderFeeCaptureService;
use App\Services\Profit\OrderProfitCalculator;
use App\Services\Profit\VatCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real fee capture (A3): measured fees are persisted (is_estimated = false), never duplicated on
 * re-capture, and flow through to the profit rollup.
 */
class OrderFeeCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);
    }

    /** A capture service wired to a canned fee list instead of a live marketplace call. */
    private function capturer(array $fees): OrderFeeCaptureService
    {
        return new class($fees) extends OrderFeeCaptureService {
            public function __construct(private array $fakeFees)
            {
            }

            protected function serviceFor(Store $store)
            {
                $fees = $this->fakeFees;

                return new class($fees) implements CapturesOrderFees {
                    public function __construct(private array $fees)
                    {
                    }

                    public function getAuthUrl(): string { return ''; }
                    public function exchangeCode(string $code): array { return []; }
                    public function refreshToken(\App\Models\Integration $i): void {}
                    public function fetchOrders(Store $s, array $p = []): array { return []; }
                    public function fetchProducts(Store $s): array { return []; }
                    public function fetchInventory(Store $s): array { return []; }
                    public function updateInventory(Store $s, string $sku, int $q): bool { return true; }
                    public function updateOrderStatus(Store $s, string $e, string $st): bool { return true; }
                    public function cancelOrder(Store $s, string $e): bool { return true; }
                    public function fetchOrderFees(Store $s, \App\Models\Order $o): array { return $this->fees; }
                };
            }
        };
    }

    private function order(): Order
    {
        return Order::create([
            'store_id' => $this->store->id,
            'external_id' => 'SH-'.uniqid(),
            'status' => 'paid',
            'total' => 100,
            'currency' => 'SAR',
        ]);
    }

    private function feeInput(string $type, string $amount, string $externalId): array
    {
        return [
            'type' => $type,
            'subtype' => 'settled',
            'amount' => $amount,
            'currency' => 'SAR',
            'external_id' => $externalId,
            'posted_at' => '2026-06-15T10:00:00Z',
            'raw' => ['x' => 1],
        ];
    }

    public function test_captured_fees_are_persisted_as_measured(): void
    {
        $order = $this->order();

        $written = $this->capturer([
            $this->feeInput(OrderFee::TYPE_COMMISSION, '30.0000', 'c-1'),
            $this->feeInput(OrderFee::TYPE_FULFILMENT, '15.0000', 'f-1'),
        ])->capture($order);

        $this->assertSame(2, $written);
        $this->assertSame(2, OrderFee::where('order_id', $order->id)->count());

        $commission = OrderFee::where('order_id', $order->id)->where('type', OrderFee::TYPE_COMMISSION)->first();
        $this->assertFalse($commission->is_estimated, 'A captured fee must be measured, not estimated.');
        $this->assertSame('settlement', $commission->source);
        $this->assertEquals(30.0, (float) $commission->amount_base);
    }

    public function test_re_capturing_updates_rather_than_duplicating(): void
    {
        $order = $this->order();
        $capturer = $this->capturer([$this->feeInput(OrderFee::TYPE_COMMISSION, '30.0000', 'c-1')]);

        $capturer->capture($order);
        $capturer->capture($order);
        $capturer->capture($order);

        $this->assertSame(1, OrderFee::where('order_id', $order->id)->count());
    }

    public function test_a_platform_that_cannot_report_fees_captures_nothing(): void
    {
        // The real (unfaked) service for salla doesn't implement CapturesOrderFees.
        $order = $this->order();
        $written = (new OrderFeeCaptureService())->capture($order);

        $this->assertSame(0, $written);
        $this->assertSame(0, OrderFee::where('order_id', $order->id)->count());
    }

    public function test_captured_fees_flow_into_the_profit_rollup(): void
    {
        CostLayer::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'acquired_at' => '2026-01-01 00:00:00',
            'qty_received' => 10,
            'qty_remaining' => 10,
            'unit_cost' => '40.0000',
            'currency' => 'SAR',
            'fx_rate_to_base' => 1,
            'unit_cost_base' => '40.0000',
        ]);

        $order = $this->order();
        OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'SKU-1',
            'name' => 'Widget',
            'quantity' => 1,
            'price' => '115.0000',
        ]);

        $this->capturer([$this->feeInput(OrderFee::TYPE_COMMISSION, '20.0000', 'c-1')])->capture($order);

        $calculator = new OrderProfitCalculator(
            $resolver = new CostResolver(),
            new FifoLedger($resolver),
            new VatCalculator(),
        );
        $profit = $calculator->calculate($order->fresh());

        // The measured commission is exactly the fee the rollup should carry.
        $this->assertEquals(20.0, (float) $profit->total_fees_base);
    }
}
