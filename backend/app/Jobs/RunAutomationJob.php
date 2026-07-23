<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\Subjects\OrderSubject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the automation engine for one order + trigger (spec 02 §5.3). Dispatched from SyncOrdersJob
 * after an order and its lines land. Idempotent by construction — the engine's application ledger
 * means a re-synced order never re-fires a rule.
 *
 * Best-effort: a rule failure must never fail the order sync that queued it.
 */
class RunAutomationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $trigger,
        public string $source = 'sync',
        public ?string $previousStatus = null,
    ) {
    }

    public function handle(AutomationEngine $engine): void
    {
        $order = Order::with(['items', 'store'])->find($this->orderId);

        if (! $order || ! $order->store) {
            return;
        }

        try {
            $engine->run(
                new OrderSubject($order, $this->previousStatus),
                $this->trigger,
                $this->source,
            );
        } catch (\Throwable $e) {
            Log::error("RunAutomationJob failed for order {$this->orderId} [{$this->trigger}]: ".$e->getMessage());
        }
    }
}
