<?php

namespace Tests\Feature;

use App\Jobs\SyncInventoryJob;
use App\Jobs\SyncOrdersJob;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        // Signature verification has its own concerns; these tests cover
        // routing a verified webhook to the right store and job.
        config([
            'services.shopify.webhook_secret' => null,
            'services.salla.webhook_secret' => null,
            'services.trendyol.webhook_secret' => null,
        ]);
    }

    /** Create a connected store with the identifiers a webhook arrives with. */
    protected function makeStore(string $platform, string $domain, ?string $platformId = null): Store
    {
        $organization = $this->makeOrganization(User::factory()->create());

        $store = Store::create([
            'organization_id' => $organization->id,
            'name' => "{$platform} store",
            'platform' => $platform,
            'domain' => $domain,
            'status' => 'connected',
        ]);

        $store->integration()->create([
            'access_token' => 'token',
            'shop_domain' => $domain,
            'platform_id' => $platformId,
        ]);

        return $store;
    }

    public function test_shopify_order_webhook_syncs_only_the_matching_store_and_order()
    {
        $store = $this->makeStore('shopify', 'acme.myshopify.com');
        $other = $this->makeStore('shopify', 'other.myshopify.com');

        $response = $this->withHeaders([
            'X-Shopify-Topic' => 'orders/updated',
            'X-Shopify-Shop-Domain' => 'acme.myshopify.com',
        ])->postJson('/api/webhooks/shopify', ['id' => 12345]);

        $response->assertOk()->assertJsonPath('status', 'success');

        Bus::assertDispatchedTimes(SyncOrdersJob::class, 1);
        Bus::assertDispatched(SyncOrdersJob::class, function (SyncOrdersJob $job) use ($store) {
            return $job->store->is($store) && $job->externalId === '12345';
        });
    }

    public function test_shopify_product_webhook_dispatches_inventory_sync_for_the_store()
    {
        $store = $this->makeStore('shopify', 'acme.myshopify.com');

        $this->withHeaders([
            'X-Shopify-Topic' => 'products/update',
            'X-Shopify-Shop-Domain' => 'acme.myshopify.com',
        ])->postJson('/api/webhooks/shopify', ['id' => 999])->assertOk();

        Bus::assertDispatched(SyncInventoryJob::class, function (SyncInventoryJob $job) use ($store) {
            return $job->store->is($store);
        });
        Bus::assertNotDispatched(SyncOrdersJob::class);
    }

    public function test_salla_order_webhook_resolves_the_store_by_merchant_id()
    {
        $store = $this->makeStore('salla', 'acme.salla.sa', platformId: '778899');

        $this->withHeaders(['X-Salla-Event' => 'order.updated'])
            ->postJson('/api/webhooks/salla', [
                'merchant' => 778899,
                'data' => ['id' => 5150],
            ])->assertOk();

        Bus::assertDispatched(SyncOrdersJob::class, function (SyncOrdersJob $job) use ($store) {
            return $job->store->is($store) && $job->externalId === '5150';
        });
    }

    public function test_trendyol_order_webhook_resolves_the_store_by_supplier_id()
    {
        $store = $this->makeStore('trendyol', '4021');

        $this->withHeaders(['X-Trendyol-Event' => 'ORDER_STATUS_CHANGED'])
            ->postJson('/api/webhooks/trendyol', [
                'supplierId' => 4021,
                'orderNumber' => 'TY-778',
            ])->assertOk();

        Bus::assertDispatched(SyncOrdersJob::class, function (SyncOrdersJob $job) use ($store) {
            return $job->store->is($store) && $job->externalId === 'TY-778';
        });
    }

    public function test_webhook_from_an_unknown_store_dispatches_nothing()
    {
        $this->makeStore('shopify', 'acme.myshopify.com');

        $response = $this->withHeaders([
            'X-Shopify-Topic' => 'orders/updated',
            'X-Shopify-Shop-Domain' => 'stranger.myshopify.com',
        ])->postJson('/api/webhooks/shopify', ['id' => 12345]);

        $response->assertOk()->assertJsonPath('status', 'ignored');
        Bus::assertNothingDispatched();
    }

    public function test_order_webhook_without_an_order_id_dispatches_nothing()
    {
        $this->makeStore('shopify', 'acme.myshopify.com');

        $this->withHeaders([
            'X-Shopify-Topic' => 'orders/updated',
            'X-Shopify-Shop-Domain' => 'acme.myshopify.com',
        ])->postJson('/api/webhooks/shopify', ['note' => 'no id here'])->assertOk();

        Bus::assertNotDispatched(SyncOrdersJob::class);
    }
}
