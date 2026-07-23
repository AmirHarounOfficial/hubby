<?php

namespace Tests\Feature;

use App\Jobs\IssueRefundJob;
use App\Models\CostLayer;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProfit;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\OrderProfitCalculator;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Two-way refund push (spec 03 slice 4). A refund on a returns-capable channel (Shopify) is issued
 * on the platform over the queue: it stays pending until the platform confirms, then settles the RMA
 * and posts the P&L. A local-only channel settles immediately (covered by ReturnRefundTest).
 *
 * Tests that need the profit to post fake ONLY IssueRefundJob so the CalculateOrderProfitJob dispatched
 * inside settleRefund still runs on the sync queue.
 */
class RefundPushTest extends TestCase
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
            'organization_id' => $this->organization->id, 'name' => 'Shop', 'platform' => 'shopify',
            'status' => 'connected', 'domain' => 'demo.myshopify.com',
        ]);
        Integration::create(['store_id' => $this->store->id, 'access_token' => 'shpat_test_token']);
        $this->service = app(ReturnService::class);
        $this->calculator = app(OrderProfitCalculator::class);
    }

    /** An inspected+restocked RMA on the Shopify store, ready to refund one of two units. */
    private function inspectedReturn(): ReturnRequest
    {
        $product = Product::create(['organization_id' => $this->organization->id, 'name' => 'W', 'sku' => 'W-1', 'price' => 100, 'stock' => 0]);
        $product->variants()->create(['sku' => 'W-1-RED', 'price' => 100, 'stock' => 10]);
        CostLayer::create([
            'organization_id' => $this->organization->id, 'sku' => 'W-1-RED', 'acquired_at' => '2026-01-01 00:00:00',
            'qty_received' => 100, 'qty_remaining' => 100, 'unit_cost' => '40.0000', 'currency' => 'SAR',
            'fx_rate_to_base' => 1, 'unit_cost_base' => '40.0000',
        ]);
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => '5500', 'status' => 'paid', 'total' => 200, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1-RED', 'name' => 'Widget', 'quantity' => 2, 'price' => 100]);
        $order = $order->fresh('items');
        $this->calculator->calculate($order);

        $rma = $this->service->create($order, [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]);
        $rma = $this->service->receive($this->service->ship($this->service->approve($rma)));
        $this->service->inspectLine($rma->items()->first(), 'new', 'restock', quantityRestock: 1);

        return $rma->fresh();
    }

    public function test_a_refund_on_shopify_is_queued_and_stays_pending(): void
    {
        Queue::fake();
        $rma = $this->inspectedReturn();

        $rma = $this->service->refund($rma);

        // Money hasn't moved yet: refund pending, RMA parked at refund_pending, profit NOT posted.
        $refund = Refund::where('return_request_id', $rma->id)->first();
        $this->assertSame('pending', $refund->status);
        $this->assertSame('shopify', $refund->gateway);
        $this->assertSame('refund_pending', $rma->fresh()->status);
        Queue::assertPushed(IssueRefundJob::class);

        $profit = OrderProfit::where('order_id', $rma->order_id)->first();
        $this->assertEquals(0.0, (float) $profit->refund_revenue_base);
    }

    public function test_the_job_pushes_to_shopify_then_settles_the_rma_and_posts_profit(): void
    {
        Http::fake([
            '*/orders/5500/transactions.json' => Http::response(['transactions' => [
                ['id' => 999, 'kind' => 'sale', 'status' => 'success'],
            ]], 200),
            '*/orders/5500/refunds.json' => Http::response(['refund' => ['id' => 74521]], 201),
        ]);

        Queue::fake([IssueRefundJob::class]); // keep the profit job on the (sync) queue
        $rma = $this->inspectedReturn();
        $this->service->refund($rma);
        $refund = Refund::where('return_request_id', $rma->id)->first();

        // Run the queued push job for real.
        (new IssueRefundJob($refund->id))->handle($this->service);

        $refund->refresh();
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('74521', $refund->external_id);
        $this->assertNotNull($refund->processed_at);
        $this->assertSame('refunded', $rma->fresh()->status);

        // Now the money really moved: ex-VAT revenue reversed (100/1.15 = 86.96).
        $profit = OrderProfit::where('order_id', $rma->order_id)->first();
        $this->assertEqualsWithDelta(86.96, (float) $profit->refund_revenue_base, 0.02);

        // A refund push actually calls the platform.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/orders/5500/refunds.json'));
    }

    public function test_a_failed_push_records_the_failure_without_refunding(): void
    {
        Http::fake([
            '*/orders/5500/transactions.json' => Http::response(['transactions' => [
                ['id' => 999, 'kind' => 'sale', 'status' => 'success'],
            ]], 200),
            '*/orders/5500/refunds.json' => Http::response(['errors' => 'gateway_declined'], 422),
        ]);

        Queue::fake([IssueRefundJob::class]);
        $rma = $this->inspectedReturn();
        $this->service->refund($rma);
        $refund = Refund::where('return_request_id', $rma->id)->first();

        try {
            (new IssueRefundJob($refund->id))->handle($this->service);
            $this->fail('expected the job to throw so the queue retries');
        } catch (\RuntimeException $e) {
            // expected
        }

        $refund->refresh();
        $this->assertSame('failed', $refund->status);
        $this->assertSame(1, (int) $refund->attempts);
        $this->assertSame('platform_refund_failed', $refund->failure_reason);
        // Local decision stands: the RMA is still parked awaiting a successful push, not refunded.
        $this->assertSame('refund_pending', $rma->fresh()->status);

        $profit = OrderProfit::where('order_id', $rma->order_id)->first();
        $this->assertEquals(0.0, (float) $profit->refund_revenue_base);
    }
}
