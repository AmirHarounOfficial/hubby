<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Automation\TemplateResolver;
use App\Services\Automation\WebhookDispatcher;
use App\Services\Integrations\IntegrationFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Executes a deferred automation action outside the rule transaction (spec 02 §3.6/§4.8): notify,
 * call_webhook, and the platform-push half of set_status. Kept off the ingest path so a slow third
 * party never stalls order sync. Retried up to 3×; on the final terminal state it PATCHes the run's
 * actions_applied entry so the audit reflects what actually happened.
 */
class ApplyAutomationActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $automationRunId,
        public int $organizationId,
        public int $orderId,
        public array $action,
        public array $facts,
        public string $idempotencyKey,
    ) {
    }

    public function handle(WebhookDispatcher $webhooks): void
    {
        $type = $this->action['type'] ?? 'unknown';

        try {
            $result = match ($type) {
                'notify' => $this->notify(),
                'call_webhook' => $this->callWebhook($webhooks),
                'set_status' => $this->pushStatus(),
                default => ['status' => 'skipped', 'error' => 'not_deferred:'.$type],
            };
            $this->patchRun($result['status'] ?? 'applied', $result);
        } catch (\Throwable $e) {
            // Only mark failed once retries are exhausted, so the audit shows the terminal state.
            if ($this->attempts() >= $this->tries) {
                $this->patchRun('failed', ['error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    private function notify(): array
    {
        $channels = $this->action['channels'] ?? ['in_app'];
        $title = TemplateResolver::render((string) ($this->action['title'] ?? 'Automation'), $this->facts)['text'];
        $message = TemplateResolver::render((string) ($this->action['message'] ?? ''), $this->facts)['text'];
        $type = $this->action['type_level'] ?? $this->action['level'] ?? 'info';

        if (in_array('in_app', $channels, true)) {
            Notification::create([
                'organization_id' => $this->organizationId,
                'title' => $title,
                'message' => $message,
                'type' => in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info',
            ]);
        }

        // email / whatsapp providers aren't wired yet (A4) — logged, not silently dropped.
        foreach (array_intersect($channels, ['email', 'whatsapp']) as $channel) {
            Log::info("Automation notify [{$channel}] queued (no provider yet) for org {$this->organizationId}: {$title}");
        }

        return ['status' => 'applied', 'channels' => $channels];
    }

    private function callWebhook(WebhookDispatcher $webhooks): array
    {
        $url = (string) ($this->action['url'] ?? '');
        $method = (string) ($this->action['method'] ?? 'POST');
        $payload = $this->buildPayload();

        $result = $webhooks->send($url, $method, $payload, $this->idempotencyKey, $this->headers());

        return ['status' => 'applied', 'response_status' => $result['status']];
    }

    private function pushStatus(): array
    {
        if (empty($this->action['push_to_platform'])) {
            return ['status' => 'skipped', 'error' => 'no_platform_push'];
        }

        $order = Order::with('store')->find($this->orderId);
        if (! $order || ! $order->store) {
            return ['status' => 'skipped', 'error' => 'order_gone'];
        }

        $service = IntegrationFactory::make($order->store->platform);
        $ok = $service->updateOrderStatus($order->store, (string) $order->external_id, (string) ($this->action['status'] ?? $order->status));

        return ['status' => $ok ? 'applied' : 'failed'];
    }

    /** @return array<string, mixed> */
    private function buildPayload(): array
    {
        $template = $this->action['payload_template'] ?? 'order';

        return match ($template) {
            'minimal' => [
                'order_id' => $this->orderId,
                'external_id' => $this->facts['order.external_id'] ?? null,
                'event' => 'automation.webhook',
            ],
            'custom' => is_array($this->action['custom_payload'] ?? null) ? $this->action['custom_payload'] : [],
            default => [
                'order_id' => $this->orderId,
                'facts' => $this->facts,
                'event' => 'automation.webhook',
            ],
        };
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = $this->action['headers'] ?? [];

        return is_array($headers)
            ? array_slice(array_map('strval', $headers), 0, 10, true)
            : [];
    }

    private function patchRun(string $status, array $extra = []): void
    {
        $run = AutomationRun::find($this->automationRunId);
        if (! $run) {
            return;
        }

        $applied = $run->actions_applied ?? [];
        $actionId = $this->action['id'] ?? null;
        foreach ($applied as &$entry) {
            if (($entry['action_id'] ?? null) === $actionId) {
                $entry['status'] = $status;
                $entry['result'] = array_merge($entry['result'] ?? [], $extra);
                break;
            }
        }
        unset($entry);

        $run->update(['actions_applied' => $applied]);
    }
}
