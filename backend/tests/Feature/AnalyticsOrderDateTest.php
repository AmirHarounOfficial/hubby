<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Defect #7: analytics used to bucket by created_at (insert time), so a first sync that pulled
 * months of history dumped it all into "today". These assert it now buckets by placed_at — the
 * order date — with a graceful fall back to created_at when the platform gives no date.
 */
class AnalyticsOrderDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private $organization;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Store',
            'platform' => 'salla',
            'status' => 'connected',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function order(array $attrs): Order
    {
        return Order::create(array_merge([
            'store_id' => $this->store->id,
            'external_id' => 'E-'.uniqid(),
            'status' => 'paid',
            'total' => 100,
            'currency' => 'SAR',
        ], $attrs));
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    public function test_dashboard_counts_an_order_in_the_window_of_its_placed_date_not_its_insert_date(): void
    {
        // Placed inside the window; only synced (created) today — the classic first-sync case.
        $this->order(['placed_at' => Carbon::now()->subDays(3), 'total' => 250]);
        // Placed long before the window, synced today: must NOT count.
        $this->order(['placed_at' => Carbon::now()->subDays(400), 'total' => 999]);

        $response = $this->getJson('/api/analytics/dashboard?days=30', $this->headers());

        $response->assertOk()
            ->assertJsonPath('total_orders', 1)
            ->assertJsonPath('total_revenue', 250);
    }

    public function test_timeline_groups_by_placed_date(): void
    {
        $this->order(['placed_at' => Carbon::parse('2026-06-10 09:00:00'), 'total' => 100]);
        $this->order(['placed_at' => Carbon::parse('2026-06-10 18:00:00'), 'total' => 50]);
        $this->order(['placed_at' => Carbon::parse('2026-06-11 12:00:00'), 'total' => 30]);

        $response = $this->getJson('/api/analytics/orders-timeline?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame('2026-06-10', $response->json('0.date'));
        $this->assertEquals(150, $response->json('0.revenue'));
        $this->assertEquals(30, $response->json('1.revenue'));
    }

    public function test_orders_without_a_placed_date_fall_back_to_created_at(): void
    {
        // No placed_at at all — created today, should still be counted in a recent window.
        $order = $this->order(['placed_at' => null, 'total' => 77]);
        $order->forceFill(['created_at' => Carbon::now()->subDay()])->save();

        $this->getJson('/api/analytics/dashboard?days=30', $this->headers())
            ->assertOk()
            ->assertJsonPath('total_orders', 1)
            ->assertJsonPath('total_revenue', 77);
    }
}
