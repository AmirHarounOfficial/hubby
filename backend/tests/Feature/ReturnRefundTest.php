<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProfit;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\OrderProfitCalculator;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refunds + profit posting + analytics (spec 03 slice 3): a refunded, restocked return must reduce
 * the order's profit — the whole reason returns exist (profit is overstated until they do).
 */
class ReturnRefundTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private ReturnService $service;
    private OrderProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->service = app(ReturnService::class);
        $this->calculator = app(OrderProfitCalculator::class);
    }

    /** An order with a cost layer + computed profit, ready to be partly returned. */
    private function costedOrder(): array
    {
        $product = Product::create(['organization_id' => $this->organization->id, 'name' => 'W', 'sku' => 'W-1', 'price' => 100, 'stock' => 0]);
        $product->variants()->create(['sku' => 'W-1-RED', 'price' => 100, 'stock' => 10]);
        CostLayer::create([
            'organization_id' => $this->organization->id, 'sku' => 'W-1-RED', 'acquired_at' => '2026-01-01 00:00:00',
            'qty_received' => 100, 'qty_remaining' => 100, 'unit_cost' => '40.0000', 'currency' => 'SAR',
            'fx_rate_to_base' => 1, 'unit_cost_base' => '40.0000',
        ]);
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'paid',
            'total' => 200, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1-RED', 'name' => 'Widget', 'quantity' => 2, 'price' => 100]);

        $order = $order->fresh('items');
        $this->calculator->calculate($order); // establishes COGS consumption

        return [$order, $order->items->first()];
    }

    public function test_a_refunded_restocked_return_reduces_order_profit(): void
    {
        [$order, $item] = $this->costedOrder();

        $before = OrderProfit::where('order_id', $order->id)->first();
        // Prices are VAT-inclusive (15%): net revenue 200/1.15 = 173.91, minus COGS 80 = 93.91.
        $this->assertEqualsWithDelta(93.91, (float) $before->net_profit_base, 0.02);

        // Return + refund 1 unit, restocked.
        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]]);
        $rma = $this->service->receive($this->service->ship($this->service->approve($rma)));
        $this->service->inspectLine($rma->items()->first(), 'new', 'restock', quantityRestock: 1);
        $this->service->refund($rma->fresh());

        $after = OrderProfit::where('order_id', $order->id)->first();
        // One unit refunded: ex-VAT revenue reversed (100/1.15 = 86.96) and its COGS recovered (+40).
        $this->assertEqualsWithDelta(86.96, (float) $after->refund_revenue_base, 0.02);
        $this->assertEqualsWithDelta(40.0, (float) $after->refund_cogs_base, 0.01);
        // 93.91 + 40 (COGS back) − 86.96 (revenue reversed) = 46.96 — the profit of the one unit kept.
        $this->assertEqualsWithDelta(46.96, (float) $after->net_profit_base, 0.05);

        $this->assertSame('refunded', $rma->fresh()->status);
        $this->assertSame(1, Refund::where('return_request_id', $rma->id)->where('status', 'succeeded')->count());
    }

    public function test_an_rto_closes_with_no_money_moved(): void
    {
        [$order, $item] = $this->costedOrder();

        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]], ['type' => 'rto']);
        $rma = $this->service->receive($this->service->ship($this->service->approve($rma)));
        $this->service->inspectLine($rma->items()->first(), 'new', 'restock', quantityRestock: 1);
        $rma = $this->service->refund($rma->fresh());

        // RTO: parcel came back, nothing refunded — closed, no refund row.
        $this->assertSame('closed', $rma->status);
        $this->assertSame(0, Refund::where('return_request_id', $rma->id)->count());
    }

    public function test_analytics_report_the_return_and_rto_rates(): void
    {
        $sanctumUser = User::factory()->create();
        $this->organization->users()->attach($sanctumUser->id, ['role' => 'owner']);
        \Laravel\Sanctum\Sanctum::actingAs($sanctumUser);

        [$order, $item] = $this->costedOrder();
        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]]);
        $this->service->receive($this->service->ship($this->service->approve($rma)));
        $this->service->inspectLine($rma->items()->first(), 'new', 'restock', quantityRestock: 1);
        $this->service->refund($rma->fresh());

        $res = $this->getJson('/api/returns/analytics', ['X-Organization-Id' => $this->organization->id]);

        $res->assertOk()
            ->assertJsonPath('total_returns', 1)
            ->assertJsonPath('rto_returns', 0);
        $this->assertSame('1.00', $res->json('restock_ratio') === null ? 'x' : number_format($res->json('restock_ratio'), 2));
        $this->assertGreaterThan(0, (float) $res->json('refund_value'));
    }
}
