<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRuleApplication;
use App\Models\AutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Subjects\OrderSubject;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rules engine end-to-end (spec 02): match → apply, nested conditions, idempotency, priority,
 * stop_processing, dry-run, and the ungated-in-every-plan pricing commitment.
 */
class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private AutomationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Salla Store', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->engine = app(AutomationEngine::class);
    }

    private function order(array $attrs = [], array $items = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $this->store->id,
            'external_id' => 'O-'.uniqid(),
            'status' => 'paid',
            'total' => 1600,
            'currency' => 'SAR',
        ], $attrs));
        foreach ($items as $it) {
            OrderItem::create(array_merge(['order_id' => $order->id, 'name' => 'Item', 'quantity' => 1, 'price' => 10], $it));
        }

        return $order->fresh('items');
    }

    private function rule(array $conditions, array $actions, array $overrides = []): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'organization_id' => $this->organization->id,
            'name' => 'Rule',
            'trigger' => 'order.created',
            'conditions' => $conditions,
            'actions' => $actions,
            'enabled' => true,
            'run_mode' => 'live',
        ], $overrides));
    }

    private function subject(Order $order): OrderSubject
    {
        return new OrderSubject($order->fresh(['items', 'store']));
    }

    public function test_a_matching_rule_applies_its_actions(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => [
                ['field' => 'order.channel', 'operator' => 'eq', 'value' => 'salla'],
                ['field' => 'order.total', 'operator' => 'gte', 'value' => 1500],
            ]],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['high-value']],
             ['id' => 'a2', 'type' => 'hold_order', 'reason' => 'manual review']],
        );

        $order = $this->order();
        $this->engine->run($this->subject($order), 'order.created', 'sync');

        $order->refresh();
        $this->assertSame(['high-value'], $order->tags);
        $this->assertTrue($order->is_held);
        $this->assertSame('matched', AutomationRun::first()->outcome);
    }

    public function test_nested_any_group(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => [
                ['field' => 'order.is_cod', 'operator' => 'is_true', 'value' => null],
                ['match' => 'any', 'rules' => [
                    ['field' => 'order.shipping_city', 'operator' => 'eq', 'value' => 'riyadh'],
                    ['field' => 'order.total', 'operator' => 'gte', 'value' => 5000],
                ]],
            ]],
            [['id' => 'a1', 'type' => 'assign_folder', 'folder' => 'cod-review']],
        );

        $order = $this->order([
            'total' => 200,
            'raw_data' => ['payment_method' => 'COD', 'shipping_address' => ['city' => 'Riyadh']],
        ]);
        $this->engine->run($this->subject($order), 'order.created', 'sync');

        $this->assertSame('cod-review', $order->fresh()->folder);
    }

    public function test_a_non_matching_rule_changes_nothing(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => [['field' => 'order.total', 'operator' => 'gte', 'value' => 100000]]],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['vip']]],
        );

        $order = $this->order(['total' => 50]);
        $this->engine->run($this->subject($order), 'order.created', 'sync');

        $this->assertNull($order->fresh()->tags);
    }

    public function test_re_running_the_same_rule_is_deduped(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => [['field' => 'order.total', 'operator' => 'gte', 'value' => 100]]],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['seen']]],
        );

        $order = $this->order();
        $this->engine->run($this->subject($order), 'order.created', 'sync');
        $this->engine->run($this->subject($order), 'order.created', 'sync'); // SyncOrdersJob re-runs

        $this->assertSame(1, AutomationRuleApplication::count());
        $this->assertSame('deduped', AutomationRun::latest('id')->first()->outcome);
    }

    public function test_stop_processing_halts_lower_priority_rules(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => []], // always match
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['first']]],
            ['priority' => 10, 'stop_processing' => true, 'name' => 'First'],
        );
        $this->rule(
            ['match' => 'all', 'rules' => []],
            [['id' => 'b1', 'type' => 'add_tag', 'tags' => ['second']]],
            ['priority' => 20, 'name' => 'Second'],
        );

        $order = $this->order();
        $this->engine->run($this->subject($order), 'order.created', 'sync');

        // Only the higher-priority (lower number) rule ran.
        $this->assertSame(['first'], $order->fresh()->tags);
    }

    public function test_dry_run_records_a_run_but_applies_nothing(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => []],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['would-tag']]],
            ['run_mode' => 'dry_run'],
        );

        $order = $this->order();
        $this->engine->run($this->subject($order), 'order.created', 'sync');

        $this->assertNull($order->fresh()->tags); // nothing applied
        $this->assertSame('simulated', AutomationRun::first()->outcome);
    }

    public function test_an_unknown_field_skips_without_error(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => [['field' => 'order.nonexistent', 'operator' => 'eq', 'value' => 'x']]],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['x']]],
        );

        $order = $this->order();
        $runs = $this->engine->run($this->subject($order), 'order.created', 'sync');

        $this->assertSame('skipped', $runs[0]->outcome);
        $this->assertStringContainsString('unknown_field', $runs[0]->error);
        $this->assertNull($order->fresh()->tags);
    }

    public function test_disabled_rules_do_not_run(): void
    {
        $this->rule(
            ['match' => 'all', 'rules' => []],
            [['id' => 'a1', 'type' => 'add_tag', 'tags' => ['x']]],
            ['enabled' => false],
        );

        $order = $this->order();
        $runs = $this->engine->run($this->subject($order), 'order.created', 'sync');

        $this->assertSame([], $runs);
        $this->assertNull($order->fresh()->tags);
    }

    public function test_automation_is_ungated_in_every_plan(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertGreaterThan(0, Plan::count());
        foreach (Plan::all() as $plan) {
            $this->assertContains(
                'Automation Rules (unlimited)',
                $plan->features,
                "Plan {$plan->slug} must include the ungated automation rules engine.",
            );
        }
    }
}
