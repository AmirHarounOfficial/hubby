<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\CostLayerConsumption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductCost;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\CostResolver;
use App\Services\Profit\FifoLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COGS recognition through FIFO layers (spec 01 §4.2, §4.3).
 */
class FifoLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected Store $store;
    protected FifoLedger $ledger;
    protected CostResolver $resolver;

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
        $this->resolver = new CostResolver();
        $this->ledger = new FifoLedger($this->resolver);
    }

    public function test_consumption_walks_layers_oldest_first_and_splits_across_them(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 3, unitCost: '10.0000');
        $this->layer('2026-02-01 00:00:00', qty: 10, unitCost: '12.0000');

        $item = $this->orderItem(quantity: 5);
        $this->ledger->consume($item);

        $rows = CostLayerConsumption::where('order_item_id', $item->id)->orderBy('id')->get();

        // 3 units from the cheaper older layer, then 2 from the newer one.
        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]->qty);
        $this->assertEquals(30.0, (float) $rows[0]->amount_base);
        $this->assertSame(2, $rows[1]->qty);
        $this->assertEquals(24.0, (float) $rows[1]->amount_base);

        // Total COGS = 54.00, not 5 × the latest price (60.00) — the whole point of FIFO.
        $this->assertEquals(54.0, (float) CostLayerConsumption::sum('amount_base'));

        $this->assertEquals(0, CostLayer::orderBy('id')->first()->qty_remaining);
        $this->assertEquals(8, CostLayer::orderBy('id')->skip(1)->first()->qty_remaining);
    }

    public function test_consuming_twice_is_a_no_op(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 10, unitCost: '10.0000');
        $item = $this->orderItem(quantity: 4);

        $this->ledger->consume($item);
        $this->ledger->consume($item);
        $this->ledger->consume($item);

        $this->assertSame(1, CostLayerConsumption::where('order_item_id', $item->id)->count());
        $this->assertEquals(40.0, (float) CostLayerConsumption::sum('amount_base'));
        $this->assertEquals(6, CostLayer::first()->qty_remaining);
    }

    public function test_layers_acquired_after_the_order_are_not_used(): void
    {
        // Stock bought after the sale cannot be what was sold.
        $this->layer('2027-01-01 00:00:00', qty: 10, unitCost: '10.0000');
        $item = $this->orderItem(quantity: 2);

        $this->ledger->consume($item);

        $this->assertSame(0, CostLayerConsumption::count());
        $this->assertEquals(10, CostLayer::first()->qty_remaining);
    }

    public function test_shortfall_falls_back_to_the_cost_definition_and_is_flagged_estimated(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 1, unitCost: '10.0000');
        ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '20.0000',
            'valid_from' => '2025-01-01',
        ]);

        $item = $this->orderItem(quantity: 3);
        $this->ledger->consume($item);

        // 1 real unit at 10, plus 2 estimated at 20.
        $this->assertEquals(50.0, (float) CostLayerConsumption::sum('amount_base'));
        $this->assertTrue(CostLayer::where('is_estimated', true)->exists());
        // The synthesised layer must never be sellable to a later order.
        $this->assertEquals(0, CostLayer::where('is_estimated', true)->first()->qty_remaining);
    }

    public function test_no_cost_anywhere_records_nothing_rather_than_guessing_zero(): void
    {
        $item = $this->orderItem(quantity: 2);

        $this->ledger->consume($item);

        $this->assertSame(0, CostLayerConsumption::count());
        $this->assertTrue($this->resolver->resolve(
            $this->organization->id, 'SKU-1', $this->store->id, now()
        )->isMissing);
    }

    public function test_restocked_refund_reverses_cogs_and_returns_stock_to_its_layer(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 10, unitCost: '10.0000');
        $item = $this->orderItem(quantity: 4);
        $this->ledger->consume($item);

        $this->ledger->reverse($item, qty: 3, restocked: true);

        // Net COGS = 4 sold - 3 returned = 1 unit.
        $this->assertEquals(10.0, (float) CostLayerConsumption::sum('amount_base'));
        // 10 - 4 consumed + 3 restocked = 9 back in the pool at the original cost.
        $this->assertEquals(9, CostLayer::first()->qty_remaining);
        $this->assertSame(
            CostLayerConsumption::REASON_REFUND_RESTOCK,
            CostLayerConsumption::where('qty', '<', 0)->first()->reason
        );
    }

    public function test_writeoff_refund_reverses_cogs_but_does_not_restore_stock(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 10, unitCost: '10.0000');
        $item = $this->orderItem(quantity: 4);
        $this->ledger->consume($item);

        $this->ledger->reverse($item, qty: 2, restocked: false);

        $this->assertEquals(20.0, (float) CostLayerConsumption::sum('amount_base'));
        // Goods were damaged/disposed — they are gone, so the layer stays depleted.
        $this->assertEquals(6, CostLayer::first()->qty_remaining);
        $this->assertSame(
            CostLayerConsumption::REASON_REFUND_WRITEOFF,
            CostLayerConsumption::where('qty', '<', 0)->first()->reason
        );
    }

    public function test_consume_after_a_refund_does_not_re_consume(): void
    {
        $this->layer('2026-01-01 00:00:00', qty: 10, unitCost: '10.0000');
        $item = $this->orderItem(quantity: 4);
        $this->ledger->consume($item);
        $this->ledger->reverse($item, qty: 4, restocked: true);

        // A later recalculation must not treat the reversed line as "not yet consumed".
        $this->ledger->consume($item);

        $this->assertEquals(0.0, (float) CostLayerConsumption::sum('amount_base'));
        $this->assertSame(1, CostLayerConsumption::where('qty', '>', 0)->count());
    }

    public function test_per_store_cost_overrides_the_org_wide_one(): void
    {
        ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '10.0000',
            'valid_from' => '2026-01-01',
        ]);
        ProductCost::create([
            'organization_id' => $this->organization->id,
            'store_id' => $this->store->id,
            'sku' => 'SKU-1',
            'unit_cost' => '14.0000',
            'valid_from' => '2026-01-01',
        ]);

        $orgWide = $this->resolver->resolve($this->organization->id, 'SKU-1', null, now());
        $perStore = $this->resolver->resolve($this->organization->id, 'SKU-1', $this->store->id, now());

        $this->assertEquals(10.0, (float) $orgWide->landedUnitCostBase);
        $this->assertEquals(14.0, (float) $perStore->landedUnitCostBase);
    }

    public function test_newer_cost_supersedes_older_and_history_is_still_queryable(): void
    {
        $old = ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '10.0000',
            'valid_from' => '2026-01-01',
        ]);
        ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '18.0000',
            'valid_from' => '2026-06-01',
        ]);

        // The observer closes the old window rather than deleting it.
        $this->assertSame('2026-06-01', $old->fresh()->valid_to->toDateString());

        // A sale in March must cost at March's price, not today's.
        $march = $this->resolver->resolve(
            $this->organization->id, 'SKU-1', null, now()->parse('2026-03-15')
        );
        $this->assertEquals(10.0, (float) $march->landedUnitCostBase);

        $july = $this->resolver->resolve(
            $this->organization->id, 'SKU-1', null, now()->parse('2026-07-15')
        );
        $this->assertEquals(18.0, (float) $july->landedUnitCostBase);
    }

    public function test_landed_cost_is_converted_to_base_currency_at_the_stored_rate(): void
    {
        $cost = ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-TRY',
            'unit_cost' => '100.0000',
            'freight_cost' => '20.0000',
            'currency' => 'TRY',
            'fx_rate_to_base' => '0.11000000',
            'valid_from' => '2026-01-01',
        ]);

        $this->assertEquals(120.0, (float) $cost->landed_unit_cost);
        // 120 TRY * 0.11 = 13.20 SAR
        $this->assertEquals(13.2, (float) $cost->landed_unit_cost_base);
    }

    private function layer(string $acquiredAt, int $qty, string $unitCost): CostLayer
    {
        return CostLayer::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'acquired_at' => $acquiredAt,
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => $unitCost,
            'unit_cost_base' => $unitCost,
        ]);
    }

    private function orderItem(int $quantity): OrderItem
    {
        $order = Order::create([
            'store_id' => $this->store->id,
            'external_id' => 'EXT-'.uniqid(),
            'status' => 'paid',
            'total' => 100,
            'currency' => 'SAR',
            'created_at' => '2026-06-01 00:00:00',
        ]);

        return OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'SKU-1',
            'name' => 'Test product',
            'quantity' => $quantity,
            'price' => 25,
        ]);
    }
}
