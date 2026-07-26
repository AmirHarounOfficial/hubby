<?php

namespace Tests\Feature;

use App\Jobs\PushInventoryJob;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inbound receiving (spec 08 §4.3): stock moves on completion, never per scan. */
class ReceivingTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Product $product;
    private $variant;
    private array $headers;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        Sanctum::actingAs($this->owner);

        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'name' => 'Abaya', 'sku' => 'AB-100', 'price' => 250, 'stock' => 0,
        ]);
        $this->variant = $this->product->variants()->create(['sku' => 'AB-100-M', 'price' => 250, 'stock' => 10]);
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id, 'barcode' => 'BC-AB-M',
        ]);

        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function scan(int $receiptId, string $barcode, array $extra = [])
    {
        return $this->postJson("/api/receipts/{$receiptId}/scan", array_merge([
            'uuid' => (string) Str::uuid(), 'barcode' => $barcode,
        ], $extra), $this->headers);
    }

    public function test_scanning_does_not_move_stock_until_completion(): void
    {
        $receipt = $this->postJson('/api/receipts', ['supplier_name' => 'Supplier A'], $this->headers)->assertCreated();
        $id = $receipt->json('id');

        $this->scan($id, 'BC-AB-M', ['qty' => 5])->assertOk();

        // Counted on the line, but the catalogue has not changed — an abandoned receipt leaks nothing.
        $this->assertSame(5, ReceiptItem::where('receipt_id', $id)->first()->qty_received);
        $this->assertSame(10, $this->variant->fresh()->stock);
        $this->assertSame('in_progress', Receipt::find($id)->status);

        $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)->assertOk();

        $this->assertSame(15, $this->variant->fresh()->stock);
        $this->assertSame('completed', Receipt::find($id)->status);
    }

    public function test_completion_writes_an_inventory_log_and_pushes_to_channels(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');
        $this->scan($id, 'BC-AB-M', ['qty' => 3]);
        $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)->assertOk();

        $log = InventoryLog::where('product_variant_id', $this->variant->id)->first();
        $this->assertSame(3, $log->change);
        $this->assertSame('Warehouse Receive', $log->source);
        Queue::assertPushed(PushInventoryJob::class);
    }

    public function test_damaged_units_are_recorded_but_never_become_sellable(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');
        $this->scan($id, 'BC-AB-M', ['qty' => 10, 'qty_damaged' => 4])->assertOk();

        $this->postJson("/api/receipts/{$id}/complete", ['accept_discrepancies' => true], $this->headers)->assertOk();

        // 10 received, 4 damaged → only 6 reach stock.
        $this->assertSame(16, $this->variant->fresh()->stock);
        $line = ReceiptItem::where('receipt_id', $id)->first();
        $this->assertSame(4, $line->qty_damaged);
        $this->assertSame('damaged', $line->discrepancy_reason);
    }

    public function test_a_case_barcode_receives_its_pack_size(): void
    {
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id, 'barcode' => 'CASE-AB', 'pack_size' => 12,
        ]);
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');

        $this->scan($id, 'CASE-AB')->assertOk(); // one scan of a case = 12 units

        $this->assertSame(12, ReceiptItem::where('receipt_id', $id)->first()->qty_received);
    }

    public function test_informed_receiving_routes_a_discrepancy_to_review(): void
    {
        $id = $this->postJson('/api/receipts', [
            'expected_lines' => [['sku' => 'AB-100-M', 'qty' => 10]],
        ], $this->headers)->json('id');

        $this->scan($id, 'BC-AB-M', ['qty' => 7]); // short by 3

        $res = $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)->assertOk();

        // Stock must NOT move until a supervisor accepts.
        $this->assertSame('review', $res->json('status'));
        $this->assertSame(10, $this->variant->fresh()->stock);

        $line = ReceiptItem::where('receipt_id', $id)->first();
        $this->assertSame(-3, $line->discrepancy);
        $this->assertSame('short', $line->discrepancy_reason);

        // Accepting applies the stock.
        $this->postJson("/api/receipts/{$id}/complete", ['accept_discrepancies' => true], $this->headers)->assertOk();
        $this->assertSame(17, $this->variant->fresh()->stock);
    }

    public function test_blind_receiving_completes_without_review(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id'); // no expected_lines
        $this->scan($id, 'BC-AB-M', ['qty' => 4]);

        $res = $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)->assertOk();

        $this->assertSame('completed', $res->json('status'));
        $this->assertSame(14, $this->variant->fresh()->stock);
    }

    public function test_an_unknown_barcode_is_received_as_unidentified_and_applies_no_stock(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');

        // Real goods on the dock with no mapping — record them rather than refusing the pallet.
        $this->scan($id, 'MYSTERY-9', ['qty' => 6])->assertOk();
        $this->postJson("/api/receipts/{$id}/complete", ['accept_discrepancies' => true], $this->headers)->assertOk();

        $line = ReceiptItem::where('receipt_id', $id)->first();
        $this->assertSame('MYSTERY-9', $line->unidentified_barcode);
        $this->assertSame(6, $line->qty_received);
        $this->assertSame(10, $this->variant->fresh()->stock); // untouched
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_replaying_a_receive_scan_never_double_counts(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'barcode' => 'BC-AB-M', 'qty' => 5, 'was_offline' => true];

        $this->postJson("/api/receipts/{$id}/scan", $body, $this->headers)->assertOk();
        $replay = $this->postJson("/api/receipts/{$id}/scan", $body, $this->headers)->assertOk();

        $this->assertTrue($replay->json('duplicate'));
        $this->assertSame(5, ReceiptItem::where('receipt_id', $id)->first()->qty_received);
    }

    public function test_a_completed_receipt_cannot_be_scanned_or_recompleted(): void
    {
        $id = $this->postJson('/api/receipts', [], $this->headers)->json('id');
        $this->scan($id, 'BC-AB-M', ['qty' => 2]);
        $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)->assertOk();

        $this->postJson("/api/receipts/{$id}/complete", [], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'RECEIPT_NOT_OPEN');
        $this->assertSame(12, $this->variant->fresh()->stock); // not applied twice
    }

    public function test_a_viewer_cannot_accept_discrepancies(): void
    {
        $id = $this->postJson('/api/receipts', ['expected_lines' => [['sku' => 'AB-100-M', 'qty' => 5]]], $this->headers)->json('id');

        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/receipts/{$id}/complete", ['accept_discrepancies' => true], $this->headers)
            ->assertForbidden();
    }
}
