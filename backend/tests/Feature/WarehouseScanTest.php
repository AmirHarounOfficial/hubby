<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ScanEvent;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Warehouse\BarcodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Barcode resolution + offline-safe scanning (spec 08 §4.0, §4.5). */
class WarehouseScanTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Product $product;
    private array $headers;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        Sanctum::actingAs($this->owner);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Abaya', 'sku' => 'AB-100', 'price' => 250, 'stock' => 40,
        ]);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function resolver(): BarcodeResolver
    {
        return app(BarcodeResolver::class);
    }

    // --- Resolution ---

    public function test_a_stored_barcode_resolves_to_its_item(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'barcode' => '4006381333931', 'symbology' => 'ean13',
        ]);

        $result = $this->resolver()->resolve($this->organization->id, '4006381333931');

        $this->assertSame('item', $result->kind);
        $this->assertSame($this->product->id, $result->product->id);
        $this->assertSame('product_barcode', $result->matchedVia);
    }

    public function test_a_upc_a_scan_matches_an_ean13_catalogue(): void
    {
        // Catalogue stores the 13-digit EAN; the scanner reads the 12-digit UPC-A off a US carton.
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'barcode' => '0036000291452', 'symbology' => 'ean13',
        ]);

        $result = $this->resolver()->resolve($this->organization->id, '036000291452');

        $this->assertSame('item', $result->kind);
        $this->assertSame($this->product->id, $result->product->id);
    }

    public function test_sku_resolves_as_a_fallback(): void
    {
        $result = $this->resolver()->resolve($this->organization->id, 'AB-100');

        $this->assertSame('item', $result->kind);
        $this->assertSame('product_sku', $result->matchedVia);
    }

    public function test_a_stored_barcode_beats_a_sku_coincidence(): void
    {
        // Another product's SKU happens to equal this barcode string; the stored mapping must win.
        $other = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Other', 'sku' => 'SHARED-1', 'price' => 10, 'stock' => 1,
        ]);
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id, 'barcode' => 'SHARED-1',
        ]);

        $result = $this->resolver()->resolve($this->organization->id, 'SHARED-1');

        $this->assertSame($this->product->id, $result->product->id);
        $this->assertNotSame($other->id, $result->product->id);
    }

    public function test_a_case_barcode_carries_its_pack_size(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'barcode' => 'CASE-AB-100', 'pack_size' => 12,
        ]);

        $this->assertSame(12, $this->resolver()->resolve($this->organization->id, 'CASE-AB-100')->packSize);
    }

    public function test_a_location_label_resolves_to_a_location(): void
    {
        $wh = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
        StockLocation::create(['organization_id' => $this->organization->id, 'warehouse_id' => $wh->id, 'code' => 'A-01-3']);

        $result = $this->resolver()->resolve($this->organization->id, 'a-01-3');

        $this->assertSame('location', $result->kind);
        $this->assertSame('A-01-3', $result->location->code);
    }

    public function test_an_order_number_resolves_to_the_order(): void
    {
        $store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        Order::create(['store_id' => $store->id, 'external_id' => 'ORD-77', 'status' => 'paid', 'total' => 10, 'currency' => 'SAR']);

        $this->assertSame('order', $this->resolver()->resolve($this->organization->id, 'ORD-77')->kind);
    }

    public function test_barcodes_do_not_leak_across_organizations(): void
    {
        $otherOrg = $this->makeOrganization(User::factory()->create(), 'Other Org');
        $otherProduct = Product::create(['organization_id' => $otherOrg->id, 'name' => 'X', 'sku' => 'X-1', 'price' => 1, 'stock' => 1]);
        ProductBarcode::create(['organization_id' => $otherOrg->id, 'product_id' => $otherProduct->id, 'barcode' => '9999999999999']);

        // Same EAN, different tenant — must not resolve here.
        $this->assertTrue($this->resolver()->resolve($this->organization->id, '9999999999999')->isUnknown());
        $this->assertSame('item', $this->resolver()->resolve($otherOrg->id, '9999999999999')->kind);
    }

    // --- Scanning endpoint ---

    public function test_scanning_records_an_event_and_returns_the_item(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id, 'barcode' => 'AB-BC-1',
        ]);

        $res = $this->postJson('/api/scan', [
            'uuid' => (string) Str::uuid(), 'barcode' => 'ab-bc-1', 'session_type' => 'lookup',
        ], $this->headers)->assertOk();

        $this->assertSame('item', $res->json('kind'));
        $this->assertSame($this->product->id, $res->json('product.id'));
        $this->assertFalse($res->json('duplicate'));
        $this->assertSame(1, ScanEvent::where('organization_id', $this->organization->id)->count());
    }

    public function test_replaying_an_offline_scan_is_idempotent(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id, 'barcode' => 'AB-BC-2',
        ]);
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'barcode' => 'AB-BC-2', 'session_type' => 'lookup', 'was_offline' => true];

        $first = $this->postJson('/api/scan', $body, $this->headers)->assertOk();
        $replay = $this->postJson('/api/scan', $body, $this->headers)->assertOk();

        $this->assertFalse($first->json('duplicate'));
        $this->assertTrue($replay->json('duplicate'));
        // Same answer, and crucially only ONE event — a flaky network never double-counts.
        $this->assertSame($first->json('product.id'), $replay->json('product.id'));
        $this->assertSame(1, ScanEvent::where('organization_id', $this->organization->id)->count());
    }

    public function test_an_unknown_barcode_is_a_404_and_still_recorded(): void
    {
        $this->postJson('/api/scan', [
            'uuid' => (string) Str::uuid(), 'barcode' => 'NOPE-1',
        ], $this->headers)->assertStatus(404)->assertJsonPath('kind', 'unknown');

        // Rejected scans are recorded too — that's how you find a missing barcode mapping.
        $this->assertSame('unknown_barcode', ScanEvent::first()->result);
    }

    public function test_a_bad_check_digit_still_resolves_but_is_flagged(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id, 'barcode' => '4006381333930',
        ]);

        $this->postJson('/api/scan', [
            'uuid' => (string) Str::uuid(), 'barcode' => '4006381333930',
        ], $this->headers)->assertOk()->assertJsonPath('check_digit_valid', false);

        // Advisory: the pick succeeded, but the label problem is on record.
        $this->assertSame('check_digit_mismatch', ScanEvent::first()->reject_reason);
    }

    // --- Setup endpoints ---

    public function test_a_default_warehouse_is_created_on_first_use(): void
    {
        $res = $this->getJson('/api/warehouses', $this->headers)->assertOk();

        $this->assertCount(1, $res->json());
        $this->assertSame('MAIN', $res->json('0.code'));
        $this->assertTrue($res->json('0.is_default'));
    }

    public function test_a_location_code_that_collides_with_an_item_is_refused(): void
    {
        $wh = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'M', 'code' => 'MAIN', 'is_default' => true]);

        // AB-100 is a product SKU; as a bin code it would resolve to the item, not the shelf.
        $this->postJson("/api/warehouses/{$wh->id}/locations", ['code' => 'AB-100'], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'LOCATION_CODE_COLLIDES');

        $this->postJson("/api/warehouses/{$wh->id}/locations", ['code' => 'A-02-1'], $this->headers)->assertCreated();
    }

    public function test_a_barcode_cannot_be_mapped_to_two_items(): void
    {
        $this->postJson('/api/barcodes', ['barcode' => 'DUP-1', 'product_id' => $this->product->id], $this->headers)->assertCreated();
        $this->postJson('/api/barcodes', ['barcode' => 'dup-1', 'product_id' => $this->product->id], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'BARCODE_ALREADY_MAPPED');
    }

    public function test_a_viewer_cannot_create_barcode_mappings(): void
    {
        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/barcodes', ['barcode' => 'V-1', 'product_id' => $this->product->id], $this->headers)
            ->assertForbidden();
    }
}
