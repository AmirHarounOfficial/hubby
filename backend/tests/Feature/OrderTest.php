<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Organization;
use App\Models\Store;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $organization;
    protected $store;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Store',
            'platform' => 'shopify',
            'status' => 'connected',
        ]);
        
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_user_can_list_orders()
    {
        Order::create([
            'store_id' => $this->store->id,
            'external_id' => '12345',
            'total' => 100.00,
            'status' => 'paid',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->getJson('/api/orders');

        $response->assertStatus(200);
        // `total` is a money value serialized as a decimal string ("100.00");
        // compare numerically rather than asserting an exact representation.
        $this->assertEquals(100.0, (float) $response->json('data.0.total'));
    }
}
