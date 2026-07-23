<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Automation rules CRUD + simulate + schema (spec 02 slice 2), and the live wiring that fires the
 * engine when an order syncs.
 */
class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private $organization;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tag high-value COD',
            'trigger' => 'order.created',
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'order.total', 'operator' => 'gte', 'value' => 1000],
            ]],
            'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['high-value']]],
            'priority' => 50,
            'run_mode' => 'live',
        ], $overrides);
    }

    public function test_crud_lifecycle(): void
    {
        // Create — defaults to disabled (safe).
        $create = $this->postJson('/api/automation/rules', $this->payload(), $this->headers())->assertStatus(201);
        $id = $create->json('id');
        $this->assertFalse($create->json('enabled'));
        $this->assertSame(1, $create->json('version'));

        // List + show.
        $this->getJson('/api/automation/rules', $this->headers())->assertOk()->assertJsonCount(1);
        $this->getJson("/api/automation/rules/{$id}", $this->headers())->assertOk()->assertJsonPath('name', 'Tag high-value COD');

        // Toggle on.
        $this->postJson("/api/automation/rules/{$id}/toggle", [], $this->headers())
            ->assertOk()->assertJsonPath('enabled', true);

        // Editing the actions bumps the version (idempotency fingerprint input).
        $this->putJson("/api/automation/rules/{$id}", $this->payload([
            'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['premium']]],
        ]), $this->headers())->assertOk()->assertJsonPath('version', 2);

        // Delete.
        $this->deleteJson("/api/automation/rules/{$id}", [], $this->headers())->assertOk();
        $this->getJson('/api/automation/rules', $this->headers())->assertJsonCount(0);
    }

    public function test_another_org_cannot_see_or_touch_a_rule(): void
    {
        $rule = AutomationRule::create(array_merge($this->payload(), ['organization_id' => $this->organization->id]));

        $stranger = User::factory()->create();
        $otherOrg = $this->makeOrganization($stranger, 'Other');
        $stranger->organizations()->attach($otherOrg->id, ['role' => 'owner']);
        Sanctum::actingAs($stranger);

        $this->getJson("/api/automation/rules/{$rule->id}", ['X-Organization-Id' => $otherOrg->id])
            ->assertStatus(404);
    }

    public function test_validation_rejects_a_bad_trigger(): void
    {
        $this->postJson('/api/automation/rules', $this->payload(['trigger' => 'nope']), $this->headers())
            ->assertStatus(422);
    }

    public function test_schema_lists_fields_operators_and_actions(): void
    {
        $res = $this->getJson('/api/automation/schema', $this->headers())
            ->assertOk()
            ->assertJsonStructure(['triggers', 'fields', 'operators', 'operatorLabels', 'actions']);

        // Plain-language labels so the builder reads like a sentence, and enum fields carry options.
        $this->assertSame('is at least', $res->json('operatorLabels.gte'));
        $channel = collect($res->json('fields'))->firstWhere('field', 'order.channel');
        $this->assertContains('salla', $channel['options']);
    }

    public function test_templates_are_ready_to_use_rules(): void
    {
        $res = $this->getJson('/api/automation/templates', $this->headers())->assertOk();

        $this->assertNotEmpty($res->json());
        $first = $res->json('0');
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('category', $first);
        // Each template embeds a complete, valid rule the builder can load and save as-is.
        $this->assertArrayHasKey('trigger', $first['rule']);
        $this->assertArrayHasKey('conditions', $first['rule']);
        $this->assertArrayHasKey('actions', $first['rule']);
    }

    public function test_simulate_reports_a_match_without_applying(): void
    {
        Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'paid',
            'total' => 2000, 'currency' => 'SAR',
        ]);

        $res = $this->postJson('/api/automation/rules/simulate', [
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'order.total', 'operator' => 'gte', 'value' => 1000],
            ]],
            'actions' => [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['x']]],
        ], $this->headers());

        $res->assertOk()->assertJsonPath('matched', true);
        $this->assertNotEmpty($res->json('actions_preview'));
        // Simulation must not persist anything.
        $this->assertSame(0, \App\Models\AutomationRun::count());
        $this->assertSame(0, \App\Models\AutomationRuleApplication::count());
    }

    public function test_run_automation_job_applies_a_live_rule_to_an_order(): void
    {
        AutomationRule::create(array_merge($this->payload(), [
            'organization_id' => $this->organization->id,
            'enabled' => true,
            'conditions' => ['match' => 'all', 'rules' => [
                ['field' => 'order.channel', 'operator' => 'eq', 'value' => 'salla'],
            ]],
        ]));

        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-9', 'status' => 'paid',
            'total' => 100, 'currency' => 'SAR',
        ]);

        (new \App\Jobs\RunAutomationJob($order->id, 'order.created', 'sync'))
            ->handle(app(\App\Services\Automation\AutomationEngine::class));

        $this->assertSame(['high-value'], $order->fresh()->tags);
    }
}
