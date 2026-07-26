<?php

namespace Tests\Feature;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cycle counting (spec 08 §4.4): blind counts, absolute quantities, live-stock variance. */
class CycleCountTest extends TestCase
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
        $this->variant = $this->product->variants()->create(['sku' => 'AB-100-M', 'price' => 250, 'stock' => 40]);
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id, 'barcode' => 'BC-ABAYA',
        ]);

        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function newSession(array $attrs = []): int
    {
        return $this->postJson('/api/count-sessions', array_merge([
            'scope_type' => 'sku_list', 'scope_ref' => ['skus' => ['AB-100-M']],
        ], $attrs), $this->headers)->assertCreated()->json('id');
    }

    private function countScan(int $id, int $qty, array $extra = [])
    {
        return $this->postJson("/api/count-sessions/{$id}/count", array_merge([
            'uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA', 'counted_qty' => $qty,
        ], $extra), $this->headers);
    }

    public function test_a_blind_count_never_sends_the_expected_quantity_to_an_operator(): void
    {
        $id = $this->newSession(['mode' => 'blind']);

        // A supervisor may see it...
        $this->getJson("/api/count-sessions/{$id}", $this->headers)
            ->assertOk()->assertJsonPath('entries.0.expected_qty', 40);

        // ...but an operator must not. Hiding it in the UI is not enough — a determined operator
        // reads the JSON, and counting what the system expects defeats the whole exercise.
        $operator = User::factory()->create();
        $this->organization->users()->attach($operator->id, ['role' => 'viewer']);
        Sanctum::actingAs($operator);

        $res = $this->getJson("/api/count-sessions/{$id}", $this->headers)->assertOk();
        $this->assertArrayNotHasKey('expected_qty', $res->json('entries.0'));
    }

    public function test_an_informed_count_does_send_the_expected_quantity(): void
    {
        $id = $this->newSession(['mode' => 'informed']);

        $operator = User::factory()->create();
        $this->organization->users()->attach($operator->id, ['role' => 'viewer']);
        Sanctum::actingAs($operator);

        $this->getJson("/api/count-sessions/{$id}", $this->headers)
            ->assertOk()->assertJsonPath('entries.0.expected_qty', 40);
    }

    public function test_counted_quantity_is_absolute_not_accumulated(): void
    {
        $id = $this->newSession();

        // The client sends a running total, not a delta — three scans of one unit each.
        $this->countScan($id, 1, ['client_seq' => 1])->assertOk();
        $this->countScan($id, 2, ['client_seq' => 2])->assertOk();
        $this->countScan($id, 3, ['client_seq' => 3])->assertOk();

        $this->assertSame(3, CountEntry::where('count_session_id', $id)->first()->counted_qty);
    }

    public function test_an_out_of_order_replay_is_ignored(): void
    {
        $id = $this->newSession();

        $this->countScan($id, 5, ['client_seq' => 5])->assertOk();
        // A late-arriving earlier scan must not roll the count backwards.
        $this->countScan($id, 2, ['client_seq' => 2])->assertOk();

        $this->assertSame(5, CountEntry::where('count_session_id', $id)->first()->counted_qty);
    }

    public function test_approval_applies_the_variance_and_logs_it(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 37, ['client_seq' => 1]); // 3 missing vs 40

        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)
            ->assertOk()->assertJsonPath('status', 'under_review');
        $this->postJson("/api/count-sessions/{$id}/approve", [], $this->headers)->assertOk();

        $this->assertSame(37, $this->variant->fresh()->stock);
        $entry = CountEntry::where('count_session_id', $id)->first();
        $this->assertSame(-3, $entry->variance);
        $this->assertSame(40, $entry->live_qty_at_approval);
        $this->assertSame(-3, InventoryLog::first()->change);
        $this->assertSame('Cycle Count', InventoryLog::first()->source);
    }

    public function test_variance_is_measured_against_live_stock_not_the_snapshot(): void
    {
        $id = $this->newSession();              // snapshot: 40
        $this->countScan($id, 38, ['client_seq' => 1]);
        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        // Two units legitimately sell while the count is being reviewed.
        $this->variant->decrement('stock', 2); // live is now 38

        $this->postJson("/api/count-sessions/{$id}/approve", [], $this->headers)->assertOk();

        // Counted 38, live 38 → no variance. Applying the stale −2 would have erased real sales.
        $entry = CountEntry::where('count_session_id', $id)->first();
        $this->assertSame(0, $entry->variance);
        $this->assertSame(38, $this->variant->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_submitting_reports_variance_at_retail_value(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 38, ['client_seq' => 1]); // −2 × 250

        $res = $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        $this->assertSame(2, $res->json('variance_units'));
        $this->assertEqualsWithDelta(-500.0, (float) $res->json('variance_value'), 0.01);
    }

    public function test_stock_never_moves_without_approval(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 10, ['client_seq' => 1]);
        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        // Submitted but not approved — the catalogue is untouched.
        $this->assertSame(40, $this->variant->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_viewer_cannot_approve_a_count(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 30, ['client_seq' => 1]);
        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/count-sessions/{$id}/approve", [], $this->headers)->assertForbidden();
        $this->assertSame(40, $this->variant->fresh()->stock);
    }

    public function test_rejecting_leaves_stock_alone(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 5, ['client_seq' => 1]);
        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        $this->postJson("/api/count-sessions/{$id}/reject", ['reason' => 'Miscounted aisle'], $this->headers)
            ->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame(40, $this->variant->fresh()->stock);
    }

    public function test_a_location_scoped_count_is_refused_while_locations_are_advisory(): void
    {
        $wh = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'M', 'code' => 'MAIN', 'is_default' => true]);
        StockLocation::create(['organization_id' => $this->organization->id, 'warehouse_id' => $wh->id, 'code' => 'A-01']);
        StockLocation::create(['organization_id' => $this->organization->id, 'warehouse_id' => $wh->id, 'code' => 'A-02']);

        // Counting one bin against a warehouse-wide scalar would report a false variance.
        $this->postJson('/api/count-sessions', ['scope_type' => 'location', 'warehouse_id' => $wh->id], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'LOCATION_SCOPED_COUNT_UNSUPPORTED');
    }

    public function test_a_location_scoped_count_is_allowed_with_a_single_location(): void
    {
        $wh = Warehouse::create(['organization_id' => $this->organization->id, 'name' => 'M', 'code' => 'MAIN', 'is_default' => true]);
        StockLocation::create(['organization_id' => $this->organization->id, 'warehouse_id' => $wh->id, 'code' => 'A-01']);

        // One location means bin == warehouse, so the variance is meaningful.
        $this->postJson('/api/count-sessions', ['scope_type' => 'location', 'warehouse_id' => $wh->id], $this->headers)
            ->assertCreated();
    }

    public function test_counting_a_closed_session_is_rejected(): void
    {
        $id = $this->newSession();
        $this->countScan($id, 40, ['client_seq' => 1]);
        $this->postJson("/api/count-sessions/{$id}/submit", [], $this->headers)->assertOk();

        $this->assertSame('session_closed', $this->countScan($id, 99, ['client_seq' => 2])->assertStatus(422)->json('result'));
    }
}
