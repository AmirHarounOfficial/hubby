<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemProfit;
use App\Models\OrderProfit;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The /analytics/profit endpoints (spec 01 §5.5).
 *
 * Two things matter here beyond the arithmetic: another tenant's margins must never leak, and
 * every figure must arrive with the coverage metadata that says how much of it is estimated.
 */
class ProfitReportTest extends TestCase
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

        Sanctum::actingAs($this->user);
    }

    public function test_summary_aggregates_the_rollups_and_reports_margin(): void
    {
        $this->profit(netRevenue: '1000.0000', cogs: '400.0000', fees: '100.0000', netProfit: '500.0000');
        $this->profit(netRevenue: '1000.0000', cogs: '600.0000', fees: '200.0000', netProfit: '200.0000');

        $response = $this->getJson('/api/analytics/profit?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()
            ->assertJsonPath('orders', 2)
            ->assertJsonPath('net_revenue', '2000.0000')
            ->assertJsonPath('cogs', '1000.0000')
            ->assertJsonPath('fees', '300.0000')
            ->assertJsonPath('net_profit', '700.0000')
            ->assertJsonPath('margin_pct', 0.35)
            ->assertJsonStructure(['coverage' => ['orders_total', 'orders_missing_cost', 'cost_coverage_pct']]);
    }

    public function test_another_organizations_profit_is_never_visible(): void
    {
        $this->profit(netRevenue: '1000.0000', cogs: '400.0000', fees: '0.0000', netProfit: '600.0000');

        $stranger = User::factory()->create();
        $otherOrg = $this->makeOrganization($stranger, 'Other Org');
        $otherStore = Store::create([
            'organization_id' => $otherOrg->id,
            'name' => 'Someone Else',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);
        $this->profit(netRevenue: '9999.0000', cogs: '0.0000', fees: '0.0000', netProfit: '9999.0000', store: $otherStore, organizationId: $otherOrg->id);

        $response = $this->getJson('/api/analytics/profit?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()
            ->assertJsonPath('orders', 1)
            ->assertJsonPath('net_profit', '600.0000');
    }

    public function test_org_header_for_an_organization_the_user_does_not_belong_to_is_rejected(): void
    {
        $stranger = User::factory()->create();
        $otherOrg = $this->makeOrganization($stranger, 'Other Org');

        $this->getJson('/api/analytics/profit', ['X-Organization-Id' => $otherOrg->id])
            ->assertStatus(403);
    }

    public function test_a_viewer_is_denied_cost_and_profit_data_by_default(): void
    {
        // A viewer-level teammate can work orders but must not see margins (org default: admin).
        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->organization->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/analytics/profit', $this->headers())->assertStatus(403);
        $this->getJson('/api/analytics/profit/by-sku', $this->headers())->assertStatus(403);
    }

    public function test_a_viewer_is_allowed_once_the_org_opens_cost_visibility(): void
    {
        $this->organization->forceFill(['cost_visibility_role' => 'viewer'])->save();

        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->organization->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/analytics/profit?start_date=2026-06-01&end_date=2026-06-30', $this->headers())
            ->assertOk();
    }

    public function test_timeline_groups_by_day(): void
    {
        $this->profit(netRevenue: '100.0000', cogs: '40.0000', fees: '0.0000', netProfit: '60.0000', placedOn: '2026-06-01');
        $this->profit(netRevenue: '200.0000', cogs: '80.0000', fees: '0.0000', netProfit: '120.0000', placedOn: '2026-06-01');
        $this->profit(netRevenue: '300.0000', cogs: '90.0000', fees: '0.0000', netProfit: '210.0000', placedOn: '2026-06-02');

        $response = $this->getJson('/api/analytics/profit/timeline?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame('300.0000', $response->json('0.net_revenue'));
        $this->assertSame('210.0000', $response->json('1.net_profit'));
    }

    public function test_by_sku_ranks_products_and_reports_per_unit_profit(): void
    {
        $order = $this->order();
        $this->line($order, sku: 'LOSER', quantity: 1, netRevenue: '50.0000', cogs: '60.0000', netProfit: '-10.0000');
        $this->line($order, sku: 'WINNER', quantity: 4, netRevenue: '400.0000', cogs: '200.0000', netProfit: '200.0000');

        $response = $this->getJson('/api/analytics/profit/by-sku?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()
            ->assertJsonPath('0.sku', 'WINNER')
            ->assertJsonPath('0.profit_per_unit', '50.0000')
            ->assertJsonPath('1.sku', 'LOSER')
            ->assertJsonPath('1.net_profit', '-10.0000');
    }

    public function test_by_channel_splits_per_store(): void
    {
        $second = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);

        $this->profit(netRevenue: '100.0000', cogs: '0.0000', fees: '0.0000', netProfit: '100.0000');
        $this->profit(netRevenue: '500.0000', cogs: '0.0000', fees: '0.0000', netProfit: '400.0000', store: $second);

        $response = $this->getJson('/api/analytics/profit/by-channel?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.platform', 'shopify')
            ->assertJsonPath('0.net_profit', '400.0000')
            ->assertJsonPath('1.platform', 'salla');
    }

    /** A merchant must be able to see how much of the margin above is guesswork. */
    public function test_coverage_reports_missing_cost_and_names_the_offending_skus(): void
    {
        $covered = $this->profit(netRevenue: '100.0000', cogs: '40.0000', fees: '0.0000', netProfit: '60.0000');
        $blind = $this->profit(netRevenue: '100.0000', cogs: '0.0000', fees: '0.0000', netProfit: '100.0000', missingCost: true);

        $this->line(Order::find($covered->order_id), sku: 'KNOWN', quantity: 1, netRevenue: '100.0000', cogs: '40.0000', netProfit: '60.0000');
        $this->line(Order::find($blind->order_id), sku: 'UNPRICED', quantity: 1, netRevenue: '100.0000', cogs: '0.0000', netProfit: '100.0000');

        $response = $this->getJson('/api/analytics/profit/coverage?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()
            ->assertJsonPath('orders_total', 2)
            ->assertJsonPath('orders_missing_cost', 1)
            ->assertJsonPath('cost_coverage_pct', 0.5)
            ->assertJsonPath('skus_missing_cost', ['UNPRICED']);
    }

    public function test_single_order_profit_returns_the_lines(): void
    {
        $profit = $this->profit(netRevenue: '100.0000', cogs: '40.0000', fees: '0.0000', netProfit: '60.0000');
        $this->line(Order::find($profit->order_id), sku: 'ABC', quantity: 2, netRevenue: '100.0000', cogs: '40.0000', netProfit: '60.0000');

        $this->getJson("/api/orders/{$profit->order_id}/profit", $this->headers())
            ->assertOk()
            ->assertJsonPath('order.net_profit_base', '60.0000')
            ->assertJsonPath('lines.0.sku', 'ABC')
            ->assertJsonPath('lines.0.quantity', 2);
    }

    public function test_an_order_with_no_calculated_profit_returns_404(): void
    {
        $order = $this->order();

        $this->getJson("/api/orders/{$order->id}/profit", $this->headers())
            ->assertStatus(404);
    }

    public function test_store_filter_narrows_the_summary(): void
    {
        $second = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);

        $this->profit(netRevenue: '100.0000', cogs: '0.0000', fees: '0.0000', netProfit: '100.0000');
        $this->profit(netRevenue: '500.0000', cogs: '0.0000', fees: '0.0000', netProfit: '400.0000', store: $second);

        $this->getJson("/api/analytics/profit?start_date=2026-06-01&end_date=2026-06-30&store_id={$second->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('orders', 1)
            ->assertJsonPath('net_profit', '400.0000');
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    private function order(?Store $store = null): Order
    {
        return Order::create([
            'store_id' => ($store ?? $this->store)->id,
            'external_id' => 'EXT-'.uniqid(),
            'status' => 'paid',
            'total' => 100,
            'currency' => 'SAR',
            'created_at' => '2026-06-01 00:00:00',
        ]);
    }

    private function profit(
        string $netRevenue,
        string $cogs,
        string $fees,
        string $netProfit,
        ?Store $store = null,
        ?int $organizationId = null,
        string $placedOn = '2026-06-01',
        bool $missingCost = false,
    ): OrderProfit {
        $store = $store ?? $this->store;

        return OrderProfit::create([
            'organization_id' => $organizationId ?? $this->organization->id,
            'order_id' => $this->order($store)->id,
            'store_id' => $store->id,
            'placed_on' => $placedOn,
            'base_currency' => 'SAR',
            'gross_revenue_base' => $netRevenue,
            'net_revenue_base' => $netRevenue,
            'cogs_base' => $cogs,
            'total_fees_base' => $fees,
            'net_profit_base' => $netProfit,
            'missing_cost' => $missingCost,
            'is_estimated' => false,
            'computed_at' => '2026-06-02 00:00:00',
        ]);
    }

    private function line(
        Order $order,
        string $sku,
        int $quantity,
        string $netRevenue,
        string $cogs,
        string $netProfit,
        string $placedOn = '2026-06-01',
    ): OrderItemProfit {
        $item = OrderItem::create([
            'order_id' => $order->id,
            'sku' => $sku,
            'name' => $sku,
            'quantity' => $quantity,
            'price' => $netRevenue,
        ]);

        return OrderItemProfit::create([
            'organization_id' => $this->organization->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'store_id' => $order->store_id,
            'sku' => $sku,
            'placed_on' => $placedOn,
            'quantity' => $quantity,
            'net_revenue_base' => $netRevenue,
            'cogs_base' => $cogs,
            'direct_fees_base' => '0.0000',
            'allocated_fees_base' => '0.0000',
            'net_profit_base' => $netProfit,
            'is_estimated' => false,
        ]);
    }
}
