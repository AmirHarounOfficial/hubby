<?php

namespace Tests\Feature;

use App\Jobs\SyncOrdersJob;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Integrations\IntegrationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end ingest through SyncOrdersJob — the path the webhook tests fake past.
 *
 * Covers three of the audited defects at once:
 *   #1 the (Store, ?externalId) constructor the webhook now dispatches with,
 *   #2 line items persisting (external_id + NOT NULL name) instead of fataling, and
 *   #3 the upsert keyed on (store_id, external_id) so one store can't clobber another's order.
 */
class SyncOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name = 'Shopify Store'): Store
    {
        $org = $this->makeOrganization(User::factory()->create());
        $store = Store::create([
            'organization_id' => $org->id,
            'name' => $name,
            'platform' => 'shopify',
            'status' => 'connected',
        ]);
        $store->integration()->create(['access_token' => 'token']);

        return $store->fresh('integration');
    }

    /** One Shopify-shaped order with two lines. */
    private function orderPayload(string $id = '12345'): array
    {
        return [[
            'id' => $id,
            'financial_status' => 'paid',
            'total_price' => '150.00',
            'currency' => 'SAR',
            'customer' => ['first_name' => 'Sara', 'last_name' => 'A', 'email' => 'sara@example.com'],
            'created_at' => '2026-06-15T10:30:00+03:00',
            'line_items' => [
                ['id' => 900, 'title' => 'Oud 50ml', 'sku' => 'OUD-50', 'quantity' => 1, 'price' => '100.00'],
                ['id' => 901, 'title' => 'Dates 1kg', 'sku' => 'DATES-1', 'quantity' => 2, 'price' => '25.00'],
            ],
        ]];
    }

    private function runSync(Store $store, array $orders, ?string $externalId = null): void
    {
        FakeSyncOrdersJob::$orders = $orders;
        (new FakeSyncOrdersJob($store, $externalId))->handle();
    }

    public function test_order_and_its_line_items_persist(): void
    {
        $store = $this->store();

        $this->runSync($store, $this->orderPayload());

        $order = Order::where('store_id', $store->id)->where('external_id', '12345')->firstOrFail();
        $this->assertEqualsWithDelta(150.0, (float) $order->total, 0.001);
        // #7: the platform order date is captured, not just the sync time.
        $this->assertSame('2026-06-15', $order->placed_at?->toDateString());

        $items = OrderItem::where('order_id', $order->id)->orderBy('external_id')->get();
        $this->assertCount(2, $items);
        // #2: name (NOT NULL) is set and the per-line external_id is captured.
        $this->assertSame('Oud 50ml', $items[0]->name);
        $this->assertSame('900', (string) $items[0]->external_id);
        $this->assertSame('DATES-1', $items[1]->sku);
    }

    public function test_re_syncing_updates_lines_instead_of_duplicating_them(): void
    {
        $store = $this->store();

        $this->runSync($store, $this->orderPayload());
        // Second delivery of the same order with a changed quantity.
        $changed = $this->orderPayload();
        $changed[0]['line_items'][0]['quantity'] = 5;
        $this->runSync($store, $changed);

        $order = Order::where('store_id', $store->id)->where('external_id', '12345')->firstOrFail();
        $this->assertSame(2, OrderItem::where('order_id', $order->id)->count());
        $this->assertSame(5, OrderItem::where('order_id', $order->id)->where('external_id', '900')->value('quantity'));
    }

    public function test_two_stores_can_hold_the_same_external_order_id(): void
    {
        $storeA = $this->store('Store A');
        $storeB = $this->store('Store B');

        $this->runSync($storeA, $this->orderPayload('SAME-1'));
        $this->runSync($storeB, $this->orderPayload('SAME-1'));

        // #3: keyed on (store_id, external_id) — one order per store, not a single clobbered row.
        $this->assertSame(1, Order::where('store_id', $storeA->id)->where('external_id', 'SAME-1')->count());
        $this->assertSame(1, Order::where('store_id', $storeB->id)->where('external_id', 'SAME-1')->count());
        $this->assertSame(2, Order::where('external_id', 'SAME-1')->count());
    }

    public function test_sync_materialises_the_profit_rollup_for_each_order(): void
    {
        $store = $this->store();

        // Queue is sync in tests, so the CalculateOrderProfitJob dispatched by the sync runs inline.
        $this->runSync($store, $this->orderPayload());

        $order = Order::where('store_id', $store->id)->where('external_id', '12345')->firstOrFail();
        $profit = \App\Models\OrderProfit::where('order_id', $order->id)->first();

        $this->assertNotNull($profit, 'syncing an order should produce its P&L rollup');
        $this->assertGreaterThan(0, (float) $profit->net_revenue_base);
        // No costs on file for these SKUs yet, so the rollup honestly flags itself.
        $this->assertTrue((bool) $profit->missing_cost);
    }

    public function test_sync_fires_the_automation_engine_on_new_orders(): void
    {
        $store = $this->store();
        \App\Models\AutomationRule::create([
            'organization_id' => $store->organization_id,
            'name' => 'Tag shopify',
            'trigger' => 'order.created',
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'order.channel', 'operator' => 'eq', 'value' => 'shopify'],
            ]],
            'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['synced-auto']]],
            'enabled' => true,
            'run_mode' => 'live',
        ]);

        // Queue is sync in tests, so the RunAutomationJob dispatched by the sync runs inline.
        $this->runSync($store, $this->orderPayload());

        $order = Order::where('store_id', $store->id)->where('external_id', '12345')->firstOrFail();
        $this->assertSame(['synced-auto'], $order->fresh()->tags);
    }

    public function test_a_status_change_on_re_sync_fires_the_status_changed_trigger(): void
    {
        $store = $this->store();
        \App\Models\AutomationRule::create([
            'organization_id' => $store->organization_id,
            'name' => 'Tag on ship',
            'trigger' => 'order.status_changed',
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'order.previous_status', 'operator' => 'eq', 'value' => 'paid'],
                ['field' => 'order.status', 'operator' => 'eq', 'value' => 'shipped'],
            ]],
            'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['just-shipped']]],
            'enabled' => true,
            'run_mode' => 'live',
        ]);

        // First sync: created as paid.
        $this->runSync($store, $this->orderPayload());
        // Re-sync with the status moved to shipped.
        $shipped = $this->orderPayload();
        $shipped[0]['financial_status'] = 'shipped';
        $this->runSync($store, $shipped);

        $order = Order::where('store_id', $store->id)->where('external_id', '12345')->firstOrFail();
        $this->assertSame('shipped', $order->status);
        $this->assertSame(['just-shipped'], $order->fresh()->tags);
    }

    public function test_webhook_scoped_sync_only_writes_the_named_order(): void
    {
        $store = $this->store();
        $orders = array_merge($this->orderPayload('AAA'), $this->orderPayload('BBB'));

        // The webhook path passes an externalId; only that order should land.
        $this->runSync($store, $orders, externalId: 'BBB');

        $this->assertSame(0, Order::where('external_id', 'AAA')->count());
        $this->assertSame(1, Order::where('external_id', 'BBB')->count());
    }
}

/** Test double: swaps the platform HTTP call for a canned payload, runs the real handle(). */
class FakeSyncOrdersJob extends SyncOrdersJob
{
    /** @var array<int, array<string, mixed>> */
    public static array $orders = [];

    protected function getService()
    {
        return new class implements IntegrationServiceInterface {
            public function getAuthUrl(): string { return ''; }
            public function exchangeCode(string $code): array { return []; }
            public function refreshToken(\App\Models\Integration $integration): void {}
            public function fetchOrders(\App\Models\Store $store, array $params = []): array { return FakeSyncOrdersJob::$orders; }
            public function fetchProducts(\App\Models\Store $store): array { return []; }
            public function fetchInventory(\App\Models\Store $store): array { return []; }
            public function updateInventory(\App\Models\Store $store, string $sku, int $qty): bool { return true; }
            public function updateOrderStatus(\App\Models\Store $store, string $externalId, string $status): bool { return true; }
            public function cancelOrder(\App\Models\Store $store, string $externalId): bool { return true; }
        };
    }
}
