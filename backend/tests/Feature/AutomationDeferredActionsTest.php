<?php

namespace Tests\Feature;

use App\Jobs\ApplyAutomationActionJob;
use App\Models\AutomationRule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Subjects\OrderSubject;
use App\Services\Automation\TemplateResolver;
use App\Services\Automation\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Spec 02 slice 4 — deferred external actions: notify, call_webhook (signed + SSRF-guarded), and
 * split_order, all queued outside the rule transaction.
 */
class AutomationDeferredActionsTest extends TestCase
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
            'organization_id' => $this->organization->id, 'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->engine = app(AutomationEngine::class);
    }

    private function order(array $items = []): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-'.uniqid(), 'status' => 'paid',
            'total' => 500, 'currency' => 'SAR',
        ]);
        foreach ($items as $it) {
            OrderItem::create(array_merge(['order_id' => $order->id, 'name' => 'Item', 'quantity' => 1, 'price' => 100], $it));
        }

        return $order->fresh(['items', 'store']);
    }

    private function rule(array $actions): AutomationRule
    {
        return AutomationRule::create([
            'organization_id' => $this->organization->id, 'name' => 'R', 'trigger' => 'order.created',
            'conditions' => ['match' => 'all', 'rules' => []], 'actions' => $actions,
            'enabled' => true, 'run_mode' => 'live',
        ]);
    }

    public function test_template_resolver_substitutes_facts_and_flags_unknowns(): void
    {
        $out = TemplateResolver::render('Order {{ order.external_id }} total {{ order.total }} — {{ order.missing }}', [
            'order.external_id' => 'ABC', 'order.total' => 1500,
        ]);
        $this->assertSame('Order ABC total 1500 — ', $out['text']);
        $this->assertContains('unknown_placeholder:order.missing', $out['warnings']);
    }

    public function test_notify_writes_an_in_app_notification(): void
    {
        $this->rule([['id' => 'a1', 'type' => 'notify', 'channels' => ['in_app'],
            'title' => 'High value', 'message' => 'Order {{ order.external_id }} needs a look', 'level' => 'warning']]);

        $order = $this->order();
        // Queue is sync in tests → the deferred ApplyAutomationActionJob runs inline.
        $this->engine->run(new OrderSubject($order), 'order.created', 'sync');

        $note = Notification::where('organization_id', $this->organization->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('High value', $note->title);
        $this->assertStringContainsString($order->external_id, $note->message);
        $this->assertSame('warning', $note->type);
    }

    public function test_call_webhook_sends_a_signed_request(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        // A literal public IP so the SSRF guard's host check needs no DNS in the sandbox.
        $this->rule([['id' => 'w1', 'type' => 'call_webhook',
            'url' => 'https://93.184.216.34/orders', 'method' => 'POST', 'payload_template' => 'minimal']]);

        $order = $this->order();
        $this->engine->run(new OrderSubject($order), 'order.created', 'sync');

        Http::assertSent(function ($request) {
            return str_starts_with($request->header('X-Hubby-Signature')[0] ?? '', 'sha256=')
                && ! empty($request->header('Idempotency-Key'))
                && $request['event'] === 'automation.webhook';
        });
    }

    public function test_webhook_refuses_a_private_or_non_https_url(): void
    {
        $dispatcher = app(WebhookDispatcher::class);

        $this->expectException(\RuntimeException::class);
        $dispatcher->send('http://localhost/admin', 'POST', [], 'key');
    }

    public function test_webhook_refuses_a_loopback_ip(): void
    {
        $this->expectException(\RuntimeException::class);
        app(WebhookDispatcher::class)->send('https://127.0.0.1/x', 'POST', [], 'key');
    }

    public function test_split_order_creates_children_and_holds_the_parent(): void
    {
        $this->rule([['id' => 's1', 'type' => 'split_order', 'strategy' => 'by_sku']]);

        $order = $this->order([
            ['sku' => 'A', 'quantity' => 2, 'price' => 100],
            ['sku' => 'B', 'quantity' => 1, 'price' => 50],
        ]);
        $this->engine->run(new OrderSubject($order), 'order.created', 'sync');

        $order->refresh();
        $children = Order::where('parent_order_id', $order->id)->orderBy('split_index')->get();
        $this->assertCount(2, $children);
        $this->assertSame($order->external_id.'-S1', $children[0]->external_id);
        // Items moved to children; parent held + tagged, out of analytics.
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->count());
        $this->assertTrue($order->is_held);
        $this->assertContains('parent_of_split', $order->tags);
        $this->assertEqualsWithDelta(200.0, (float) $children->firstWhere('external_id', $order->external_id.'-S1')->total, 0.01);
    }

    public function test_deferred_actions_are_queued_after_commit_not_run_inline(): void
    {
        Bus::fake();

        $this->rule([['id' => 'w1', 'type' => 'call_webhook', 'url' => 'https://hooks.example.com/x']]);
        $order = $this->order();
        $this->engine->run(new OrderSubject($order), 'order.created', 'sync');

        Bus::assertDispatched(ApplyAutomationActionJob::class, function ($job) use ($order) {
            return $job->orderId === $order->id && ($job->action['type'] ?? null) === 'call_webhook';
        });
    }
}
