<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
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

    public function test_user_can_list_stores()
    {
        Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Shopify Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->getJson('/api/stores');

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_can_set_master_store()
    {
        $store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Master Store',
            'platform' => 'shopify',
            'status' => 'connected',
            'is_master' => false,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->postJson("/api/stores/{$store->id}/set-master");

        $response->assertStatus(200);
        $this->assertTrue($store->fresh()->is_master);
    }
}
