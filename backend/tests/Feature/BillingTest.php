<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
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

        Plan::create([
            'name' => 'Professional',
            'slug' => 'professional',
            'price' => 79.00,
            'description' => 'Test Plan',
            'features' => ['Feature 1', 'Feature 2'],
        ]);
    }

    public function test_user_can_list_plans()
    {
        $response = $this->getJson('/api/billing/plans');

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_can_subscribe_to_plan()
    {
        $plan = Plan::first();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'X-Organization-Id' => $this->organization->id,
        ])->postJson('/api/billing/subscribe', [
            'plan_id' => $plan->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }
}
