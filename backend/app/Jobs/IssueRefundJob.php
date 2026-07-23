<?php

namespace App\Jobs;

use App\Models\Refund;
use App\Services\Integrations\IntegrationFactory;
use App\Services\Integrations\SupportsReturnsInterface;
use App\Services\Returns\ReturnService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a pending refund to the channel that owns the order (spec 03 §5.4).
 *
 * Idempotent: a refund already `succeeded` is a no-op, so a retry never double-refunds. On success
 * the money has actually moved, so we settle the RMA (→ refunded) and re-post the order's P&L. On
 * failure we record the reason and bump attempts, but never roll back the local decision — the
 * merchant still sees the return as resolved locally and can retry or refund out-of-band.
 */
class IssueRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(private readonly int $refundId)
    {
    }

    public function handle(ReturnService $returns): void
    {
        $refund = Refund::with('order', 'store.integration', 'store.organization', 'returnRequest')->find($this->refundId);

        if (! $refund || $refund->status === 'succeeded') {
            return; // gone, or already settled — never refund twice.
        }

        $store = $refund->store;
        $service = IntegrationFactory::make($store->platform);

        if (! $service instanceof SupportsReturnsInterface || ! $service->supportsReturnCapability('refund')) {
            // Store lost the capability (disconnected / reconfigured): settle locally so the RMA
            // isn't stuck, leaving the merchant to reconcile the money out-of-band.
            $returns->settleRefund($refund, null);

            return;
        }

        $result = $service->refundOrder($store, (string) $refund->order->external_id, [
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'note' => 'Refund for RMA '.($refund->returnRequest?->rma_number ?? $refund->order->external_id),
            'gateway' => $refund->gateway,
        ]);

        if ($result === null) {
            $returns->failRefund($refund, 'platform_refund_failed');
            Log::warning("IssueRefundJob: platform refund failed for refund {$refund->id} (attempt {$this->attempts()}).");

            // Let the queue retry with backoff; on the last attempt the failure state stands.
            throw new \RuntimeException('platform_refund_failed');
        }

        $returns->settleRefund($refund, $result['id'] !== '' ? $result['id'] : null);
    }
}
