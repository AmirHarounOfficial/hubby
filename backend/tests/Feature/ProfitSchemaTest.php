<?php

namespace Tests\Feature;

use App\Models\CostLayer;
use App\Models\FxRate;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\ProductCost;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Profit & Cost Engine foundation (spec 01, slice 1): the schema exists, the
 * money math is exact, the idempotency keys hold, and tax/discount are excluded from cost.
 */
class ProfitSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected $organization;
    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Salla Store',
            'platform' => 'salla',
            'status' => 'connected',
        ]);
    }

    public function test_organization_gets_mena_profit_defaults(): void
    {
        $org = $this->organization->fresh();

        $this->assertSame('SAR', $org->base_currency);
        $this->assertEquals(0.15, (float) $org->default_vat_rate);
        // MENA storefronts quote VAT-inclusive prices.
        $this->assertTrue((bool) $org->prices_include_vat);
    }

    public function test_landed_unit_cost_sums_components_exactly(): void
    {
        $cost = new ProductCost([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '10.1050',
            'freight_cost' => '2.2025',
            'duty_cost' => '1.0000',
            'prep_cost' => '0.5500',
            'other_cost' => '0.1425',
        ]);

        // 10.1050 + 2.2025 + 1.0000 + 0.5500 + 0.1425 = 14.0000 exactly.
        $this->assertSame('14.0000', $cost->computeLandedUnitCost());
    }

    public function test_cost_layers_pop_in_fifo_order_with_deterministic_tiebreak(): void
    {
        // NOTE: array_merge, not `+` — the union operator keeps the left-hand value and would
        // silently ignore the qty_remaining override below.
        $layer = fn (array $attrs) => CostLayer::create(array_merge([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'qty_received' => 5,
            'qty_remaining' => 5,
        ], $attrs));

        $older = $layer(['acquired_at' => '2026-01-01 10:00:00', 'unit_cost' => '10.0000']);
        // Same timestamp as $tie2 — the id tiebreak must make ordering reproducible.
        $tie1 = $layer(['acquired_at' => '2026-02-01 10:00:00', 'unit_cost' => '11.0000']);
        $tie2 = $layer(['acquired_at' => '2026-02-01 10:00:00', 'unit_cost' => '12.0000']);
        // Depleted layers must not appear in the queue, even though this one is the oldest.
        $layer(['acquired_at' => '2025-01-01 10:00:00', 'unit_cost' => '9.0000', 'qty_remaining' => 0]);

        $queue = CostLayer::query()
            ->fifoQueue($this->organization->id, 'SKU-1')
            ->pluck('id')
            ->all();

        $this->assertSame([$older->id, $tie1->id, $tie2->id], $queue);
    }

    public function test_fee_key_is_deterministic_and_unique_per_store(): void
    {
        $order = $this->makeOrder();

        $key = OrderFee::buildFeeKey('EXT-1', OrderFee::TYPE_COMMISSION, 'referral', 'FEE-9');
        $this->assertSame(
            $key,
            OrderFee::buildFeeKey('EXT-1', OrderFee::TYPE_COMMISSION, 'referral', 'FEE-9'),
            'The same fee must always produce the same key, or settlement re-imports duplicate.'
        );

        $this->makeFee($order, OrderFee::TYPE_COMMISSION, '5.00', $key);

        $this->expectException(QueryException::class);
        $this->makeFee($order, OrderFee::TYPE_COMMISSION, '5.00', $key);
    }

    public function test_tax_and_discount_are_excluded_from_cost_bearing_fees(): void
    {
        $order = $this->makeOrder();

        $this->makeFee($order, OrderFee::TYPE_COMMISSION, '10.0000', 'k-commission');
        $this->makeFee($order, OrderFee::TYPE_SHIPPING, '5.0000', 'k-shipping');
        // Recorded for reconciliation, but must never be counted as a cost —
        // VAT is handled by the VAT model and discounts are already netted out of revenue.
        $this->makeFee($order, OrderFee::TYPE_TAX, '15.0000', 'k-tax');
        $this->makeFee($order, OrderFee::TYPE_DISCOUNT, '7.0000', 'k-discount');

        $costBearing = OrderFee::query()->costBearing()->sum('amount_base');

        $this->assertEquals(15.0, (float) $costBearing);
    }

    public function test_negative_fee_amount_represents_a_reimbursement(): void
    {
        $order = $this->makeOrder();
        $this->makeFee($order, OrderFee::TYPE_COMMISSION, '10.0000', 'k-charge');
        $this->makeFee($order, OrderFee::TYPE_REFUND, '-4.0000', 'k-credit');

        $this->assertEquals(6.0, (float) OrderFee::query()->costBearing()->sum('amount_base'));
    }

    public function test_fx_rate_lookup_falls_back_to_the_latest_earlier_date(): void
    {
        FxRate::create(['base' => 'SAR', 'quote' => 'TRY', 'date' => '2026-01-01', 'rate' => '0.11000000']);
        FxRate::create(['base' => 'SAR', 'quote' => 'TRY', 'date' => '2026-03-01', 'rate' => '0.13000000']);

        // Same currency never needs a rate row.
        $this->assertSame('1', FxRate::rateFor('SAR', 'SAR'));
        // A date between rows uses the most recent earlier rate, not the newest one.
        $this->assertEquals(0.11, (float) FxRate::rateFor('SAR', 'TRY', '2026-02-15'));
        $this->assertEquals(0.13, (float) FxRate::rateFor('SAR', 'TRY', '2026-06-01'));
        $this->assertNull(FxRate::rateFor('SAR', 'JPY', '2026-06-01'));
    }

    public function test_cost_history_is_soft_deleted_not_destroyed(): void
    {
        $cost = ProductCost::create([
            'organization_id' => $this->organization->id,
            'sku' => 'SKU-1',
            'unit_cost' => '10.0000',
            'valid_from' => '2026-01-01',
        ]);

        $cost->delete();

        $this->assertSoftDeleted('product_costs', ['id' => $cost->id]);
        $this->assertNotNull(ProductCost::withTrashed()->find($cost->id));
    }

    private function makeOrder(): Order
    {
        return Order::create([
            'store_id' => $this->store->id,
            'external_id' => 'EXT-1',
            'status' => 'paid',
            'total' => 100,
            'currency' => 'SAR',
        ]);
    }

    private function makeFee(Order $order, string $type, string $amount, string $feeKey): OrderFee
    {
        return OrderFee::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'type' => $type,
            'amount' => $amount,
            'amount_base' => $amount,
            'currency' => 'SAR',
            'fee_key' => $feeKey,
        ]);
    }
}
