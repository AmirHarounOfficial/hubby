<?php

namespace Tests\Feature;

use App\Models\FeeRule;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\FeeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule-based fee estimation (spec 01 §6) — the honest fallback for the platforms that never
 * report what they charged.
 */
class FeeEstimatorTest extends TestCase
{
    use RefreshDatabase;

    protected $organization;
    protected Store $store;
    protected FeeEstimator $estimator;

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
        $this->estimator = new FeeEstimator();
    }

    public function test_percent_of_item_rule_creates_a_flagged_estimated_fee_per_line(): void
    {
        $this->rule(type: OrderFee::TYPE_COMMISSION, basis: FeeRule::BASIS_PERCENT_OF_ITEM, rate: '10.0000');
        $order = $this->order();
        $this->item($order, quantity: 2, price: '50.0000'); // line total 100
        $this->item($order, quantity: 1, price: '200.0000');

        $fees = $this->estimator->estimate($order->fresh());

        $this->assertCount(2, $fees);
        $this->assertEquals(30.0, (float) OrderFee::sum('amount_base')); // 10 + 20
        // Every modelled number must say so, or a merchant will read it as measured.
        $this->assertTrue(OrderFee::get()->every(fn ($f) => $f->is_estimated));
        $this->assertSame('rule', OrderFee::first()->source);
    }

    public function test_percent_of_order_creates_a_single_order_level_fee(): void
    {
        $this->rule(type: OrderFee::TYPE_PAYMENT, basis: FeeRule::BASIS_PERCENT_OF_ORDER, rate: '2.7500');
        $order = $this->order();
        $this->item($order, quantity: 2, price: '50.0000');
        $this->item($order, quantity: 1, price: '100.0000');

        $this->estimator->estimate($order->fresh());

        $fee = OrderFee::first();
        $this->assertNull($fee->order_item_id, 'An order-level fee must not be attached to a line.');
        $this->assertEquals(5.5, (float) $fee->amount_base); // 2.75% of 200
    }

    public function test_flat_per_unit_scales_with_quantity_and_flat_per_order_does_not(): void
    {
        $this->rule(type: OrderFee::TYPE_FULFILMENT, basis: FeeRule::BASIS_FLAT_PER_UNIT, rate: '3.0000');
        $this->rule(type: OrderFee::TYPE_SHIPPING, basis: FeeRule::BASIS_FLAT_PER_ORDER, rate: '15.0000');
        $order = $this->order();
        $this->item($order, quantity: 4, price: '50.0000');

        $this->estimator->estimate($order->fresh());

        $this->assertEquals(12.0, (float) OrderFee::where('type', OrderFee::TYPE_FULFILMENT)->sum('amount_base'));
        $this->assertEquals(15.0, (float) OrderFee::where('type', OrderFee::TYPE_SHIPPING)->sum('amount_base'));
    }

    public function test_min_and_max_clamp_the_estimate(): void
    {
        $this->rule(
            type: OrderFee::TYPE_PAYMENT,
            basis: FeeRule::BASIS_PERCENT_OF_ORDER,
            rate: '1.0000',
            min: '5.0000',
            max: '20.0000'
        );

        // 1% of 100 = 1.00, floored to the 5.00 minimum.
        $small = $this->order();
        $this->item($small, quantity: 1, price: '100.0000');
        $this->estimator->estimate($small->fresh());
        $this->assertEquals(5.0, (float) OrderFee::where('order_id', $small->id)->sum('amount_base'));

        // 1% of 10,000 = 100.00, capped at 20.00.
        $large = $this->order();
        $this->item($large, quantity: 1, price: '10000.0000');
        $this->estimator->estimate($large->fresh());
        $this->assertEquals(20.0, (float) OrderFee::where('order_id', $large->id)->sum('amount_base'));
    }

    public function test_a_measured_fee_is_never_overwritten_by_an_estimate(): void
    {
        $this->rule(type: OrderFee::TYPE_COMMISSION, basis: FeeRule::BASIS_PERCENT_OF_ITEM, rate: '10.0000');
        $order = $this->order();
        $this->item($order, quantity: 1, price: '100.0000');

        // A real settlement figure landed first.
        OrderFee::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'type' => OrderFee::TYPE_COMMISSION,
            'amount' => '7.5000',
            'amount_base' => '7.5000',
            'currency' => 'SAR',
            'is_estimated' => false,
            'source' => 'settlement',
            'fee_key' => 'real-commission',
        ]);

        $this->estimator->estimate($order->fresh());

        $this->assertSame(1, OrderFee::where('type', OrderFee::TYPE_COMMISSION)->count());
        $this->assertEquals(7.5, (float) OrderFee::sum('amount_base'));
    }

    public function test_re_estimating_updates_rather_than_duplicates(): void
    {
        $this->rule(type: OrderFee::TYPE_COMMISSION, basis: FeeRule::BASIS_PERCENT_OF_ITEM, rate: '10.0000');
        $order = $this->order();
        $this->item($order, quantity: 1, price: '100.0000');

        $this->estimator->estimate($order->fresh());
        $this->estimator->estimate($order->fresh());
        $this->estimator->estimate($order->fresh());

        $this->assertSame(1, OrderFee::count());
        $this->assertEquals(10.0, (float) OrderFee::sum('amount_base'));
    }

    public function test_more_specific_rule_wins_over_the_general_one(): void
    {
        // System-wide default for the platform.
        FeeRule::create([
            'organization_id' => null,
            'platform' => 'salla',
            'type' => OrderFee::TYPE_COMMISSION,
            'basis' => FeeRule::BASIS_PERCENT_OF_ITEM,
            'rate' => '10.0000',
            'effective_from' => '2020-01-01',
        ]);
        // This merchant negotiated a better rate.
        $this->rule(type: OrderFee::TYPE_COMMISSION, basis: FeeRule::BASIS_PERCENT_OF_ITEM, rate: '4.0000');

        $order = $this->order();
        $this->item($order, quantity: 1, price: '100.0000');

        $this->estimator->estimate($order->fresh());

        $this->assertSame(1, OrderFee::count());
        $this->assertEquals(4.0, (float) OrderFee::sum('amount_base'));
    }

    public function test_rules_for_another_platform_or_outside_the_date_window_do_not_apply(): void
    {
        FeeRule::create([
            'organization_id' => $this->organization->id,
            'platform' => 'shopify', // different platform
            'type' => OrderFee::TYPE_COMMISSION,
            'basis' => FeeRule::BASIS_PERCENT_OF_ITEM,
            'rate' => '10.0000',
            'effective_from' => '2020-01-01',
        ]);
        // Right platform, but expired before this order.
        $this->rule(
            type: OrderFee::TYPE_PAYMENT,
            basis: FeeRule::BASIS_PERCENT_OF_ITEM,
            rate: '5.0000',
            from: '2020-01-01',
            to: '2020-12-31'
        );

        $order = $this->order();
        $this->item($order, quantity: 1, price: '100.0000');

        $this->estimator->estimate($order->fresh());

        $this->assertSame(0, OrderFee::count());
    }

    private function rule(
        string $type,
        string $basis,
        string $rate,
        ?string $min = null,
        ?string $max = null,
        string $from = '2020-01-01',
        ?string $to = null,
    ): FeeRule {
        return FeeRule::create([
            'organization_id' => $this->organization->id,
            'platform' => 'salla',
            'type' => $type,
            'basis' => $basis,
            'rate' => $rate,
            'min_amount' => $min,
            'max_amount' => $max,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'store_id' => $this->store->id,
            'external_id' => 'EXT-'.uniqid(),
            'status' => 'paid',
            'total' => 200,
            'currency' => 'SAR',
            'created_at' => '2026-06-01 00:00:00',
        ]);
    }

    private function item(Order $order, int $quantity, string $price): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Test product',
            'quantity' => $quantity,
            'price' => $price,
        ]);
    }
}
