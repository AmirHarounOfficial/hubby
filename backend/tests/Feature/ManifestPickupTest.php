<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** End-of-day manifests + pickups (spec 04 §4.10) over the manual carrier (local document). */
class ManifestPickupTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private CarrierAccount $account;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->organization->users()->attach($owner->id, ['role' => 'owner']);
        Sanctum::actingAs($owner);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->account = CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual', 'environment' => 'sandbox', 'credentials' => [], 'is_active' => true,
        ]);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function labelPurchasedShipment(string $ref, float $weight = 2): Shipment
    {
        return Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'carrier_account_id' => $this->account->id, 'carrier_code' => 'manual',
            'reference' => $ref, 'status' => 'label_purchased', 'tracking_number' => 'AWB-'.$ref,
            'total_weight_kg' => $weight, 'currency' => 'SAR',
        ]);
    }

    public function test_manifesting_bundles_shipments_and_moves_them_to_awaiting_pickup(): void
    {
        $a = $this->labelPurchasedShipment('SHP-2026-000001', 2);
        $b = $this->labelPurchasedShipment('SHP-2026-000002', 3);

        $res = $this->postJson('/api/manifests', [
            'carrier_account_id' => $this->account->id,
            'shipment_ids' => [$a->id, $b->id],
        ], $this->headers)->assertCreated();

        $this->assertSame('confirmed', $res->json('status'));
        $this->assertSame(2, $res->json('shipment_count'));
        $this->assertEqualsWithDelta(5.0, (float) $res->json('total_weight_kg'), 0.01);
        $this->assertNull($res->json('carrier_manifest_id')); // local, not carrier-acknowledged

        $this->assertSame('awaiting_pickup', $a->fresh()->status);
        $this->assertSame('awaiting_pickup', $b->fresh()->status);
        $this->assertSame($res->json('id'), $a->fresh()->manifest_id);

        // A local manifest document was generated and is streamable.
        $doc = $this->getJson("/api/manifests/{$res->json('id')}/document", $this->headers);
        // (document endpoint streams, not JSON — assert via a raw GET)
        $raw = $this->get("/api/manifests/{$res->json('id')}/document", $this->headers)->assertOk();
        $this->assertStringContainsString('text/html', $raw->headers->get('Content-Type'));
        $this->assertSame(1, ShippingLabel::where('type', 'manifest')->count());
    }

    public function test_manifest_rejects_shipments_not_in_label_purchased(): void
    {
        $draft = Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'carrier_account_id' => $this->account->id, 'carrier_code' => 'manual',
            'reference' => 'SHP-2026-000003', 'status' => 'draft', 'currency' => 'SAR',
        ]);

        $this->postJson('/api/manifests', [
            'carrier_account_id' => $this->account->id, 'shipment_ids' => [$draft->id],
        ], $this->headers)->assertStatus(422)->assertJsonPath('code', 'MANIFEST_NO_ELIGIBLE_SHIPMENTS');
    }

    public function test_a_pickup_can_be_booked_and_cancelled(): void
    {
        $res = $this->postJson('/api/pickups', [
            'carrier_account_id' => $this->account->id,
            'pickup_date' => now()->addDay()->toDateString(),
            'ready_at' => '09:00', 'close_at' => '17:00', 'pieces' => 5, 'total_weight_kg' => 12,
        ], $this->headers)->assertCreated();

        $this->assertSame('requested', $res->json('status')); // manual carrier: local, unconfirmed
        $this->assertMatchesRegularExpression('/^PKP-\d{4}-\d{6}$/', $res->json('reference'));

        $this->deleteJson("/api/pickups/{$res->json('id')}", [], $this->headers)
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }

    public function test_a_viewer_cannot_create_a_manifest(): void
    {
        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $s = $this->labelPurchasedShipment('SHP-2026-000009');
        $this->postJson('/api/manifests', [
            'carrier_account_id' => $this->account->id, 'shipment_ids' => [$s->id],
        ], $this->headers)->assertForbidden();
    }
}
