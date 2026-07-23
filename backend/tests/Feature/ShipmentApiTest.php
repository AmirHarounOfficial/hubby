<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Shipping API: carrier accounts + shipment lifecycle over the engine (spec 04 §5.7). */
class ShipmentApiTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->organization->users()->attach($owner->id, ['role' => 'owner']);
        Sanctum::actingAs($owner);
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function order(): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'paid', 'total' => 200, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W', 'name' => 'Widget', 'quantity' => 1, 'price' => 100]);

        return $order->fresh('items');
    }

    private function manualAccount(): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual',
            'environment' => 'sandbox', 'credentials' => ['secret' => 'x'], 'is_active' => true,
        ]);
    }

    public function test_the_carrier_catalog_marks_manual_available_and_real_carriers_coming_soon(): void
    {
        $res = $this->getJson('/api/shipping/carriers', $this->headers)->assertOk();

        $manual = collect($res->json('carriers'))->firstWhere('code', 'manual');
        $aramex = collect($res->json('carriers'))->firstWhere('code', 'aramex');
        $this->assertTrue($manual['available']);
        $this->assertFalse($aramex['available']);
    }

    public function test_creating_a_carrier_account_never_returns_the_credentials(): void
    {
        $res = $this->postJson('/api/shipping/accounts', [
            'carrier_code' => 'manual', 'label' => 'My manual', 'credentials' => ['passkey' => 'topsecret'],
        ], $this->headers)->assertCreated();

        $this->assertArrayNotHasKey('credentials', $res->json());
        $this->assertTrue($res->json('has_credentials'));
    }

    public function test_a_viewer_cannot_create_a_shipment(): void
    {
        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $order = $this->order();
        $this->postJson('/api/shipments', ['order_id' => $order->id, 'weight_kg' => 2], $this->headers)
            ->assertForbidden();
    }

    public function test_full_lifecycle_draft_label_track_delivered(): void
    {
        $order = $this->order();
        $account = $this->manualAccount();

        $draft = $this->postJson('/api/shipments', [
            'order_id' => $order->id, 'weight_kg' => 1.5, 'tracking_number' => 'AWB-1',
        ], $this->headers)->assertCreated();
        $shipmentId = $draft->json('id');
        $this->assertSame('draft', $draft->json('status'));

        $label = $this->postJson("/api/shipments/{$shipmentId}/label", [
            'carrier_account_id' => $account->id,
        ], $this->headers)->assertOk();
        $this->assertSame('label_purchased', $label->json('status'));
        $this->assertSame('manual', $label->json('carrier_code'));

        // Manual tracking entry moves it to delivered, and the order rolls up.
        $this->postJson("/api/shipments/{$shipmentId}/tracking-events", [
            'status' => 'delivered', 'event_at' => '2026-07-22T15:00:00Z', 'city' => 'Riyadh',
        ], $this->headers)->assertOk();

        $this->getJson("/api/shipments/{$shipmentId}", $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
        $this->assertSame('delivered', $order->fresh()->fulfillment_status);
    }

    public function test_a_draft_can_be_deleted_but_a_purchased_one_cannot(): void
    {
        $order = $this->order();
        $account = $this->manualAccount();
        $draftId = $this->postJson('/api/shipments', ['order_id' => $order->id, 'weight_kg' => 1], $this->headers)->json('id');

        // Purchase → no longer deletable.
        $this->postJson("/api/shipments/{$draftId}/label", ['carrier_account_id' => $account->id], $this->headers)->assertOk();
        $this->deleteJson("/api/shipments/{$draftId}", [], $this->headers)->assertStatus(422);

        // A fresh draft is deletable.
        $freshDraftId = $this->postJson('/api/shipments', ['order_id' => $order->id, 'weight_kg' => 1], $this->headers)->json('id');
        $this->deleteJson("/api/shipments/{$freshDraftId}", [], $this->headers)->assertNoContent();
    }
}
