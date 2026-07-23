<?php

namespace Tests\Feature;

use App\Exceptions\InvalidReturnTransition;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnEvent;
use App\Models\ReturnReason;
use App\Models\Store;
use App\Models\User;
use App\Services\Returns\ReturnService;
use App\Services\Returns\ReturnStateMachine;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Returns/RMA foundation (spec 03 slice 1): create → approve → ship → receive → inspect, with a
 * restock writing real inventory back, a validated state machine, and an audit trail.
 */
class ReturnsTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private ReturnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->service = app(ReturnService::class);
    }

    /** @return array{0: Order, 1: OrderItem, 2: ProductVariant} */
    private function orderWithStock(int $orderedQty = 3, int $currentStock = 10): array
    {
        $product = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Widget', 'sku' => 'W-1', 'price' => 100, 'stock' => 0,
        ]);
        $variant = $product->variants()->create(['sku' => 'W-1-RED', 'price' => 100, 'stock' => $currentStock]);
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'delivered',
            'total' => 100 * $orderedQty, 'currency' => 'SAR', 'customer_name' => 'Sara', 'customer_email' => 's@x.com',
        ]);
        $item = OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1-RED', 'name' => 'Widget Red', 'quantity' => $orderedQty, 'price' => 100]);

        return [$order->fresh('items'), $item, $variant];
    }

    public function test_the_reason_taxonomy_seeds_globally(): void
    {
        $this->seed(ReturnReasonSeeder::class);

        $this->assertSame(23, ReturnReason::whereNull('organization_id')->count());
        $this->assertContains('cod_payment_refused', ReturnReason::RTO_CODES);
        $this->assertTrue(ReturnReason::where('code', 'defective')->first()->is_defect);
    }

    public function test_creating_a_return_snapshots_the_lines_and_numbers_the_rma(): void
    {
        [$order, $item] = $this->orderWithStock();

        $rma = $this->service->create($order, [
            ['order_item_id' => $item->id, 'quantity' => 2, 'reason_code' => 'wrong_size'],
        ]);

        $this->assertSame('requested', $rma->status);
        $this->assertStringStartsWith('RMA-', $rma->rma_number);
        $this->assertCount(1, $rma->items);
        $this->assertSame(2, $rma->items->first()->quantity_requested);
        $this->assertEquals(200.0, (float) $rma->items_subtotal);
        // Creation is audited.
        $this->assertSame('requested', ReturnEvent::where('return_request_id', $rma->id)->first()->to_status);
    }

    public function test_you_cannot_return_more_than_was_ordered(): void
    {
        [$order, $item] = $this->orderWithStock(orderedQty: 1);

        $this->expectException(\RuntimeException::class);
        $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 5]]);
    }

    public function test_full_lifecycle_restocks_inventory_and_computes_the_refund(): void
    {
        [$order, $item, $variant] = $this->orderWithStock(orderedQty: 3, currentStock: 10);

        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 2]]);
        $rma = $this->service->approve($rma);
        $this->assertSame('approved', $rma->status);
        $this->assertEquals(200.0, (float) $rma->total_refund); // 2 × 100

        $rma = $this->service->ship($rma);
        $this->assertSame('in_transit', $rma->status);

        $rma = $this->service->receive($rma);
        $this->assertSame('received', $rma->status);

        // Grade the line: restock both units.
        $line = $rma->items()->first();
        $this->service->inspectLine($line, condition: 'new', disposition: 'restock', quantityRestock: 2);

        $rma->refresh();
        $this->assertSame('inspected', $rma->status);
        // Inventory came back: 10 + 2, and it's traceable in the ledger.
        $this->assertSame(12, $variant->fresh()->stock);
        $this->assertDatabaseHas('inventory_logs', ['product_variant_id' => $variant->id, 'change' => 2, 'source' => 'return']);
        $this->assertNotNull($line->fresh()->inventory_log_id);
    }

    public function test_scrapped_lines_do_not_restore_stock(): void
    {
        [$order, $item, $variant] = $this->orderWithStock(orderedQty: 2, currentStock: 5);

        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]]);
        $rma = $this->service->receive($this->service->ship($this->service->approve($rma)));

        $this->service->inspectLine($rma->items()->first(), condition: 'damaged', disposition: 'scrap', quantityScrap: 1);

        $this->assertSame(5, $variant->fresh()->stock); // unchanged — a scrapped return isn't resold
    }

    public function test_the_state_machine_rejects_an_illegal_jump(): void
    {
        [$order, $item] = $this->orderWithStock();
        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]]);

        // requested → refunded is not an edge in the graph.
        $this->expectException(InvalidReturnTransition::class);
        (function () use ($rma) {
            ReturnStateMachine::assert($rma->status, 'refunded');
        })();
    }

    public function test_a_marketplace_managed_return_cannot_be_approved(): void
    {
        [$order, $item] = $this->orderWithStock();
        $rma = $this->service->create($order, [['order_item_id' => $item->id, 'quantity' => 1]], ['is_marketplace_managed' => true]);

        $this->expectException(\RuntimeException::class);
        $this->service->approve($rma);
    }
}
