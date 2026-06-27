<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $organization;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_user_can_adjust_inventory()
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Leather Bag',
            'sku' => 'LB-001',
            'price' => 100,
            'stock' => 10,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->postJson('/api/inventory/adjust', [
            'product_id' => $product->id,
            'change' => 5,
            'reason' => 'Restock',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(15, $product->fresh()->stock);
        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $product->id,
            'change' => 5,
        ]);
    }

    public function test_user_can_view_inventory_logs()
    {
        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Leather Bag',
            'sku' => 'LB-001',
            'price' => 100,
            'stock' => 10,
        ]);

        $product->increment('stock', 5);
        \App\Models\InventoryLog::create([
            'product_id' => $product->id,
            'change' => 5,
            'reason' => 'Test',
            'source' => 'Manual',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->getJson('/api/inventory/logs');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.change', 5);
    }
}
