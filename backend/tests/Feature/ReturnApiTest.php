<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\ReturnReasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The returns REST API (spec 03 slice 2) — the queue + RMA lifecycle over HTTP, org-scoped, with
 * domain guards surfacing as 422s.
 */
class ReturnApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private $organization;
    private Store $store;
    private Order $order;
    private OrderItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $product = Product::create(['organization_id' => $this->organization->id, 'name' => 'Widget', 'sku' => 'W-1', 'price' => 100, 'stock' => 0]);
        $product->variants()->create(['sku' => 'W-1-RED', 'price' => 100, 'stock' => 8]);
        $this->order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'delivered', 'total' => 300, 'currency' => 'SAR',
        ]);
        $this->item = OrderItem::create(['order_id' => $this->order->id, 'sku' => 'W-1-RED', 'name' => 'Widget', 'quantity' => 3, 'price' => 100]);
        Sanctum::actingAs($this->user);
    }

    private function h(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    private function create(int $qty = 2): int
    {
        return $this->postJson('/api/returns', [
            'order_id' => $this->order->id,
            'lines' => [['order_item_id' => $this->item->id, 'quantity' => $qty, 'reason_code' => 'wrong_size']],
        ], $this->h())->assertStatus(201)->json('id');
    }

    public function test_reasons_endpoint_returns_the_global_taxonomy(): void
    {
        $this->seed(ReturnReasonSeeder::class);
        $this->getJson('/api/return-reasons', $this->h())->assertOk()->assertJsonCount(23);
    }

    public function test_full_lifecycle_over_http_restocks_inventory(): void
    {
        $id = $this->create(2);
        $this->getJson('/api/returns', $this->h())->assertOk()->assertJsonPath('data.0.rma_number', fn ($v) => str_starts_with($v, 'RMA-'));

        $this->postJson("/api/returns/{$id}/approve", [], $this->h())->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/returns/{$id}/ship", [], $this->h())->assertOk()->assertJsonPath('status', 'in_transit');
        $this->postJson("/api/returns/{$id}/receive", [], $this->h())->assertOk()->assertJsonPath('status', 'received');

        $itemId = $this->getJson("/api/returns/{$id}", $this->h())->json('items.0.id');
        $this->postJson("/api/returns/{$id}/inspect", [
            'items' => [['return_item_id' => $itemId, 'condition' => 'new', 'disposition' => 'restock', 'quantity_restock' => 2]],
        ], $this->h())->assertOk();

        $this->getJson("/api/returns/{$id}", $this->h())->assertJsonPath('status', 'inspected');
        // 8 + 2 back in stock.
        $this->assertSame(10, (int) \App\Models\ProductVariant::where('sku', 'W-1-RED')->value('stock'));
    }

    public function test_an_illegal_action_returns_422_with_the_transition_code(): void
    {
        $id = $this->create(1);
        // Receiving before approve/ship is not a legal edge (requested → received).
        $this->postJson("/api/returns/{$id}/receive", [], $this->h())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_RETURN_TRANSITION');
    }

    public function test_over_returning_is_a_422_rule_violation(): void
    {
        $this->postJson('/api/returns', [
            'order_id' => $this->order->id,
            'lines' => [['order_item_id' => $this->item->id, 'quantity' => 99]],
        ], $this->h())->assertStatus(422)->assertJsonPath('code', 'RETURN_RULE_VIOLATION');
    }

    public function test_another_org_cannot_see_a_return(): void
    {
        $id = $this->create(1);

        $stranger = User::factory()->create();
        $otherOrg = $this->makeOrganization($stranger, 'Other');
        $stranger->organizations()->attach($otherOrg->id, ['role' => 'owner']);
        Sanctum::actingAs($stranger);

        $this->getJson("/api/returns/{$id}", ['X-Organization-Id' => $otherOrg->id])->assertStatus(404);
    }
}
