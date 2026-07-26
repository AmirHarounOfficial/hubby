<?php

namespace Tests\Feature;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PackSession;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pick + pack (spec 08 §4.1/§4.2) — mispick prevention and the no-stock-at-pick rule. */
class PickPackTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private Product $abaya;
    private Product $scarf;
    private $abayaVariant;
    private array $headers;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        Sanctum::actingAs($this->owner);

        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);

        $this->abaya = Product::create(['organization_id' => $this->organization->id, 'name' => 'Abaya', 'sku' => 'AB-100', 'price' => 250, 'stock' => 50]);
        $this->abayaVariant = $this->abaya->variants()->create(['sku' => 'AB-100-M', 'price' => 250, 'stock' => 50]);
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->abaya->id,
            'product_variant_id' => $this->abayaVariant->id, 'barcode' => 'BC-ABAYA',
        ]);

        $this->scarf = Product::create(['organization_id' => $this->organization->id, 'name' => 'Scarf', 'sku' => 'SC-1', 'price' => 40, 'stock' => 20]);
        ProductBarcode::create([
            'organization_id' => $this->organization->id, 'product_id' => $this->scarf->id, 'barcode' => 'BC-SCARF',
        ]);

        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function order(int $qty = 2, string $sku = 'AB-100-M'): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-'.Str::random(5), 'status' => 'paid',
            'total' => 500, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'name' => 'Abaya M', 'quantity' => $qty, 'price' => 250]);

        return $order->fresh('items');
    }

    private function startedList(Order $order): PickList
    {
        $id = $this->postJson('/api/pick-lists', ['order_ids' => [$order->id]], $this->headers)->assertCreated()->json('id');
        $this->postJson("/api/pick-lists/{$id}/start", [], $this->headers)->assertOk();

        return PickList::find($id);
    }

    private function pick(int $listId, string $barcode, array $extra = [])
    {
        return $this->postJson("/api/pick-lists/{$listId}/pick", array_merge([
            'uuid' => (string) Str::uuid(), 'barcode' => $barcode,
        ], $extra), $this->headers);
    }

    // --- Pick ---

    public function test_picking_never_moves_stock(): void
    {
        $order = $this->order(2);
        $list = $this->startedList($order);

        $this->pick($list->id, 'BC-ABAYA')->assertOk();
        $this->pick($list->id, 'BC-ABAYA')->assertOk();
        $this->postJson("/api/pick-lists/{$list->id}/complete", [], $this->headers)->assertOk();

        // Goods leave the building at ship, not at pick — deducting here would double-count.
        $this->assertSame(50, $this->abayaVariant->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
        $this->assertSame('completed', $list->fresh()->status);
        $this->assertSame('picked', PickListItem::where('pick_list_id', $list->id)->first()->status);
    }

    public function test_scanning_the_wrong_item_is_a_hard_block(): void
    {
        $list = $this->startedList($this->order(1));

        // The scarf is not on this list — this is the mispick the whole feature exists to stop.
        $res = $this->pick($list->id, 'BC-SCARF')->assertStatus(422);

        $this->assertSame('wrong_item', $res->json('result'));
        $this->assertSame(0, PickListItem::where('pick_list_id', $list->id)->first()->qty_picked);
    }

    public function test_over_picking_is_blocked(): void
    {
        $list = $this->startedList($this->order(1));

        $this->pick($list->id, 'BC-ABAYA')->assertOk();
        $res = $this->pick($list->id, 'BC-ABAYA')->assertStatus(422); // line already complete

        $this->assertSame('over_pick', $res->json('result'));
        $this->assertSame(1, PickListItem::where('pick_list_id', $list->id)->first()->qty_picked);
    }

    public function test_a_replayed_pick_scan_never_double_counts(): void
    {
        $list = $this->startedList($this->order(2));
        $uuid = (string) Str::uuid();
        $body = ['uuid' => $uuid, 'barcode' => 'BC-ABAYA', 'was_offline' => true];

        $this->postJson("/api/pick-lists/{$list->id}/pick", $body, $this->headers)->assertOk();
        $replay = $this->postJson("/api/pick-lists/{$list->id}/pick", $body, $this->headers)->assertOk();

        $this->assertTrue($replay->json('duplicate'));
        $this->assertSame(1, PickListItem::where('pick_list_id', $list->id)->first()->qty_picked);
    }

    public function test_picking_a_closed_list_is_rejected(): void
    {
        $order = $this->order(1);
        $id = $this->postJson('/api/pick-lists', ['order_ids' => [$order->id]], $this->headers)->json('id');

        // Never started — not pickable.
        $this->assertSame('session_closed', $this->pick($id, 'BC-ABAYA')->assertStatus(422)->json('result'));
    }

    public function test_a_short_routes_the_list_to_review_until_a_supervisor_accepts(): void
    {
        $list = $this->startedList($this->order(3));
        $line = PickListItem::where('pick_list_id', $list->id)->first();

        $this->pick($list->id, 'BC-ABAYA')->assertOk();
        $this->postJson("/api/pick-lists/{$list->id}/items/{$line->id}/short", ['reason' => 'not_found'], $this->headers)->assertOk();

        $res = $this->postJson("/api/pick-lists/{$list->id}/complete", [], $this->headers)->assertOk();
        $this->assertSame('review', $res->json('status'));

        $accepted = $this->postJson("/api/pick-lists/{$list->id}/complete", ['accept_shorts' => true], $this->headers)->assertOk();
        $this->assertSame('completed', $accepted->json('status'));
    }

    public function test_a_damaged_short_is_the_only_pick_path_that_moves_stock(): void
    {
        $list = $this->startedList($this->order(3));
        $line = PickListItem::where('pick_list_id', $list->id)->first();

        $this->postJson("/api/pick-lists/{$list->id}/items/{$line->id}/short", [
            'reason' => 'damaged', 'qty_short' => 2,
        ], $this->headers)->assertOk();

        // Damaged units are genuinely gone, so they leave the catalogue.
        $this->assertSame(48, $this->abayaVariant->fresh()->stock);
        $log = InventoryLog::first();
        $this->assertSame(-2, $log->change);
        $this->assertSame('Warehouse Pick', $log->source);
    }

    public function test_a_not_found_short_does_not_move_stock(): void
    {
        $list = $this->startedList($this->order(3));
        $line = PickListItem::where('pick_list_id', $list->id)->first();

        $this->postJson("/api/pick-lists/{$list->id}/items/{$line->id}/short", [
            'reason' => 'not_found', 'qty_short' => 2,
        ], $this->headers)->assertOk();

        // "Not on the shelf right now" is not the same as "gone".
        $this->assertSame(50, $this->abayaVariant->fresh()->stock);
        $this->assertSame(0, InventoryLog::count());
    }

    public function test_a_batch_pick_aggregates_the_same_sku_across_orders(): void
    {
        $a = $this->order(2);
        $b = $this->order(3);

        $res = $this->postJson('/api/pick-lists', ['order_ids' => [$a->id, $b->id]], $this->headers)->assertCreated();

        $this->assertSame('batch', $res->json('type'));
        $lines = PickListItem::where('pick_list_id', $res->json('id'))->get();
        $this->assertCount(1, $lines);           // one walk to the shelf
        $this->assertSame(5, $lines->first()->qty_required); // 2 + 3
    }

    // --- Pack ---

    public function test_packing_verifies_every_item_before_closing(): void
    {
        $order = $this->order(2);
        $session = $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)->assertCreated();
        $id = $session->json('id');

        // Closing a half-packed box is exactly the error that ships a short order.
        $this->postJson("/api/pack-sessions/{$id}/scan", ['uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA'], $this->headers)->assertOk();
        $this->postJson("/api/pack-sessions/{$id}/complete", [], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'PACK_SESSION_NOT_VERIFIED');

        $this->postJson("/api/pack-sessions/{$id}/scan", ['uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA'], $this->headers)->assertOk();
        $this->assertSame('verified', PackSession::find($id)->status);

        $this->postJson("/api/pack-sessions/{$id}/complete", ['weight_grams' => 800], $this->headers)
            ->assertOk()->assertJsonPath('status', 'closed');
    }

    public function test_packing_a_wrong_item_is_a_hard_block(): void
    {
        $order = $this->order(1);
        $id = $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)->json('id');

        // An item in the wrong box is a return, a refund and a bad review.
        $res = $this->postJson("/api/pack-sessions/{$id}/scan", [
            'uuid' => (string) Str::uuid(), 'barcode' => 'BC-SCARF',
        ], $this->headers)->assertStatus(422);

        $this->assertSame('wrong_item', $res->json('result'));
    }

    public function test_over_packing_is_blocked(): void
    {
        $order = $this->order(1);
        $id = $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)->json('id');

        $this->postJson("/api/pack-sessions/{$id}/scan", ['uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA'], $this->headers)->assertOk();
        $res = $this->postJson("/api/pack-sessions/{$id}/scan", ['uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA'], $this->headers)->assertStatus(422);

        $this->assertSame('over_pick', $res->json('result'));
    }

    public function test_a_second_box_only_asks_for_what_the_first_did_not_take(): void
    {
        $order = $this->order(3);
        $first = $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)->json('id');
        $this->postJson("/api/pack-sessions/{$first}/scan", ['uuid' => (string) Str::uuid(), 'barcode' => 'BC-ABAYA'], $this->headers)->assertOk();

        // Multi-box: the same unit must never be claimed by two boxes.
        $second = $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)->assertCreated();

        $this->assertSame(2, $second->json('package_index'));
        $this->assertSame(2, $second->json('items.0.qty_required')); // 3 ordered − 1 already packed
    }

    public function test_a_shipped_order_cannot_be_packed(): void
    {
        $order = $this->order(1);
        $order->update(['status' => 'shipped']);

        $this->postJson('/api/pack-sessions', ['order_id' => $order->id], $this->headers)
            ->assertStatus(422)->assertJsonPath('code', 'ORDER_NOT_PACKABLE');
    }

    public function test_a_viewer_cannot_accept_shorts(): void
    {
        $list = $this->startedList($this->order(2));

        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/pick-lists/{$list->id}/complete", ['accept_shorts' => true], $this->headers)
            ->assertForbidden();
    }
}
