<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\OrderItemProfit;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\CostResolver;
use App\Services\Profit\FifoLedger;
use App\Services\Profit\OrderProfitCalculator;
use App\Services\Profit\VatCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end profit assembly (spec 01 §4.6) — the numbers a merchant actually sees.
 */
class OrderProfitCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected Store $store;
    protected OrderProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Salla Store',
            'platform' => 'salla',
            'status' => 'connected',
        ]);

        $resolver = new CostResolver();
        $this->calculator = new OrderProfitCalculator(
            $resolver,
            new FifoLedger($resolver),
            new VatCalculator(),
        );
    }

    public function test_full_profit_calculation_on_a_vat_inclusive_order(): void
    {
        // 2 units sold at SAR 115 inclusive of 15% VAT => 200.00 net revenue, 30.00 VAT.
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order();
        $item = $this->item($order, quantity: 2, price: '115.0000');
        $this->fee($order, OrderFee::TYPE_COMMISSION, '20.0000');

        $profit = $this->calculator->calculate($order->fresh());

        $this->assertEquals(230.0, (float) $profit->gross_revenue_base);
        $this->assertEquals(200.0, (float) $profit->net_revenue_base);
        // VAT is a liability, never revenue and never profit.
        $this->assertEquals(30.0, (float) $profit->vat_base);
        $this->assertEquals(80.0, (float) $profit->cogs_base);      // 2 × 40 from FIFO
        $this->assertEquals(20.0, (float) $profit->total_fees_base);
        // 200 − 80 − 20 = 100
        $this->assertEquals(100.0, (float) $profit->net_profit_base);
        $this->assertEqualsWithDelta(0.5, (float) $profit->margin_pct, 0.0001);
        $this->assertFalse($profit->missing_cost);
    }

    public function test_tax_and_discount_fee_lines_never_reduce_profit(): void
    {
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order();
        $this->item($order, quantity: 1, price: '115.0000');
        $this->fee($order, OrderFee::TYPE_TAX, '15.0000');
        $this->fee($order, OrderFee::TYPE_DISCOUNT, '10.0000');

        $profit = $this->calculator->calculate($order->fresh());

        // Both recorded for reconciliation, neither counted as cost.
        $this->assertEquals(0.0, (float) $profit->total_fees_base);
        $this->assertEquals(60.0, (float) $profit->net_profit_base); // 100 − 40
    }

    public function test_order_level_fees_are_allocated_across_lines_and_always_sum_to_the_whole(): void
    {
        $this->layer(qty: 20, unitCost: '10.0000', sku: 'SKU-A');
        $this->layer(qty: 20, unitCost: '10.0000', sku: 'SKU-B');
        $order = $this->order();
        // Deliberately awkward split: 3 lines and a fee that does not divide evenly.
        $this->item($order, quantity: 1, price: '115.0000', sku: 'SKU-A');
        $this->item($order, quantity: 1, price: '115.0000', sku: 'SKU-B');
        $this->item($order, quantity: 1, price: '115.0000', sku: 'SKU-A');
        $this->fee($order, OrderFee::TYPE_SHIPPING, '10.0000'); // order-level

        $this->calculator->calculate($order->fresh());

        $allocated = OrderItemProfit::sum('allocated_fees_base');
        // No cent may be lost or invented in the split.
        $this->assertEquals(10.0, (float) $allocated);
    }

    public function test_fully_discounted_order_allocates_by_quantity_instead_of_dividing_by_zero(): void
    {
        $this->layer(qty: 10, unitCost: '5.0000');
        $order = $this->order();
        $this->item($order, quantity: 1, price: '0.0000');
        $this->item($order, quantity: 3, price: '0.0000');
        $this->fee($order, OrderFee::TYPE_SHIPPING, '8.0000');

        $profit = $this->calculator->calculate($order->fresh());

        $this->assertEquals(0.0, (float) $profit->net_revenue_base);
        // Margin is undefined, not zero or infinity.
        $this->assertNull($profit->margin_pct);
        $this->assertEquals(8.0, (float) OrderItemProfit::sum('allocated_fees_base'));
    }

    public function test_missing_cost_is_flagged_rather_than_silently_counted_as_zero(): void
    {
        $order = $this->order();
        $this->item($order, quantity: 1, price: '115.0000'); // no layer, no cost definition

        $profit = $this->calculator->calculate($order->fresh());

        $this->assertTrue($profit->missing_cost);
        $this->assertTrue($profit->is_estimated);
        $this->assertEquals(0.0, (float) $profit->cogs_base);
        // The margin looks impossibly good — deliberately, so it gets questioned.
        $this->assertEquals(100.0, (float) $profit->net_profit_base);
    }

    public function test_uncommitted_order_does_not_consume_stock(): void
    {
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order(status: 'pending');
        $this->item($order, quantity: 2, price: '115.0000');

        $this->calculator->calculate($order->fresh());

        // A cart that never paid must not burn inventory cost.
        $this->assertEquals(10, CostLayer::first()->qty_remaining);
    }

    public function test_recalculating_is_idempotent(): void
    {
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order();
        $this->item($order, quantity: 2, price: '115.0000');
        $this->fee($order, OrderFee::TYPE_COMMISSION, '20.0000');

        $first = $this->calculator->calculate($order->fresh());
        $second = $this->calculator->calculate($order->fresh());
        $third = $this->calculator->calculate($order->fresh());

        $this->assertSame($first->id, $third->id);
        $this->assertEquals((float) $first->net_profit_base, (float) $third->net_profit_base);
        $this->assertEquals(100.0, (float) $second->net_profit_base);
        $this->assertSame(1, \App\Models\OrderProfit::count());
        // Stock consumed exactly once across three runs.
        $this->assertEquals(8, CostLayer::first()->qty_remaining);
    }

    public function test_restocked_refund_adds_recovered_cogs_back_to_profit(): void
    {
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order();
        $item = $this->item($order, quantity: 2, price: '115.0000');

        $this->calculator->calculate($order->fresh());

        $resolver = new CostResolver();
        (new FifoLedger($resolver))->reverse($item, qty: 1, restocked: true);
        $profit = $this->calculator->calculate($order->fresh());

        // 40 of COGS came back on the shelf.
        $this->assertEquals(40.0, (float) $profit->refund_cogs_base);
        $this->assertEquals(0.0, (float) $profit->lost_cogs_base);
        // Net COGS is now 40; leaving it at 80 would penalise the restock twice.
        $this->assertEquals(40.0, (float) $profit->cogs_base);
    }

    public function test_written_off_refund_is_recorded_as_a_loss_not_recovered(): void
    {
        $this->layer(qty: 10, unitCost: '40.0000');
        $order = $this->order();
        $item = $this->item($order, quantity: 2, price: '115.0000');
        $this->calculator->calculate($order->fresh());

        $resolver = new CostResolver();
        (new FifoLedger($resolver))->reverse($item, qty: 1, restocked: false);
        $profit = $this->calculator->calculate($order->fresh());

        $this->assertEquals(40.0, (float) $profit->lost_cogs_base);
        $this->assertEquals(0.0, (float) $profit->refund_cogs_base);
    }

    private function layer(int $qty, string $unitCost, string $sku = 'SKU-1'): CostLayer
    {
        return CostLayer::create([
            'organization_id' => $this->organization->id,
            'sku' => $sku,
            'acquired_at' => '2026-01-01 00:00:00',
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $unitCost,
            'unit_cost_base' => $unitCost,
        ]);
    }

    private function order(string $status = 'paid'): Order
    {
        return Order::create([
            'store_id' => $this->store->id,
            'external_id' => 'EXT-'.uniqid(),
            'status' => $status,
            'total' => 230,
            'currency' => 'SAR',
            'created_at' => '2026-06-01 00:00:00',
        ]);
    }

    private function item(Order $order, int $quantity, string $price, string $sku = 'SKU-1'): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'sku' => $sku,
            'name' => 'Test product',
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }

    private function fee(Order $order, string $type, string $amount): OrderFee
    {
        return OrderFee::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'type' => $type,
            'amount' => $amount,
            'amount_base' => $amount,
            'currency' => 'SAR',
            'fee_key' => $type.'-'.uniqid(),
        ]);
    }
}
