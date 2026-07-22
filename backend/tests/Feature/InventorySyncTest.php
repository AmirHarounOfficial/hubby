<?php

namespace Tests\Feature;

use App\Jobs\PushInventoryJob;
use App\Models\User;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_adjusting_a_variant_pushes_the_new_level_to_channels(): void
    {
        Queue::fake();

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Leather Bag',
            'sku' => 'LB-001',
            'price' => 100,
            'stock' => 10,
        ]);
        $variant = $product->variants()->create(['sku' => 'LB-001-RED', 'price' => 100, 'stock' => 4]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->postJson('/api/inventory/adjust', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'change' => 6,
            'reason' => 'Restock',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(10, $variant->fresh()->stock);

        // Defect #4: the adjustment must actually reach the channels, carrying the new level.
        Queue::assertPushed(PushInventoryJob::class, fn ($job) => $this->jobVariantId($job) === $variant->id);
    }

    public function test_product_level_adjustment_fans_out_to_every_variant(): void
    {
        Queue::fake();

        $product = Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Leather Bag',
            'sku' => 'LB-002',
            'price' => 100,
            'stock' => 10,
        ]);
        $product->variants()->create(['sku' => 'LB-002-S', 'price' => 100, 'stock' => 1]);
        $product->variants()->create(['sku' => 'LB-002-M', 'price' => 100, 'stock' => 1]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->postJson('/api/inventory/adjust', [
            'product_id' => $product->id,
            'change' => 5,
            'reason' => 'Recount',
        ])->assertStatus(200);

        Queue::assertPushed(PushInventoryJob::class, 2);
    }

    /** Reach the protected variant the job was constructed with, without changing the job. */
    private function jobVariantId(PushInventoryJob $job): int
    {
        return (new \ReflectionClass($job))->getProperty('variant')->getValue($job)->id;
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
