<?php

namespace App\Services\Returns;

use App\Jobs\CalculateOrderProfitJob;
use App\Jobs\IssueRefundJob;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnEvent;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Services\Integrations\IntegrationFactory;
use App\Services\Integrations\SupportsReturnsInterface;
use App\Services\Profit\FifoLedger;
use Illuminate\Support\Facades\DB;

/**
 * The RMA lifecycle (spec 03 §4). Slice 1 covers the operational core — create a return from an
 * order, approve per-line quantities, receive the parcel, and grade each line, with a restock
 * writing real inventory back (stock + an inventory_log) so returned goods actually re-enter stock.
 *
 * Every status change goes through transition(), which the state machine validates and which writes
 * a return_events audit row in the same transaction — the audit never lags the state.
 *
 * Refunds post into the P&L (slice 3) and, on returns-capable channels (Shopify/Woo), push to the
 * platform over the queue (slice 4); local-only channels settle immediately. Deferred to later
 * slices: RTO auto-detection (needs carrier tracking, Spec 04), marketplace-managed mirrors, and
 * the customer portal.
 */
class ReturnService
{
    public function __construct(
        private readonly RefundCalculator $refundCalculator,
        private readonly FifoLedger $ledger,
    ) {
    }

    /**
     * Create a `requested` RMA from an order.
     *
     * @param array<int, array{order_item_id:int, quantity:int, reason_code?:string, note?:string}> $lines
     */
    public function create(Order $order, array $lines, array $attrs = []): ReturnRequest
    {
        $store = $order->store;

        return DB::transaction(function () use ($order, $store, $lines, $attrs) {
            $rma = ReturnRequest::create(array_merge([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'order_id' => $order->id,
                'rma_number' => $this->nextRmaNumber((int) $store->organization_id),
                'type' => 'customer_return',
                'origin' => 'dashboard',
                'status' => 'requested',
                'currency' => $order->currency ?? 'SAR',
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'requested_at' => now(),
            ], $attrs));

            $subtotal = 0.0;
            foreach ($lines as $line) {
                $item = $order->items()->findOrFail($line['order_item_id']);
                $qty = max(1, (int) $line['quantity']);

                $alreadyReturned = $this->quantityAlreadyReturned($item->id, $rma->id);
                if ($qty > (int) $item->quantity - $alreadyReturned) {
                    throw new \RuntimeException("return_quantity_exceeds_order_line:{$item->id}");
                }

                ReturnItem::create([
                    'return_request_id' => $rma->id,
                    'order_item_id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity_requested' => $qty,
                    'unit_price' => $item->price,
                    'reason_code' => $line['reason_code'] ?? ($attrs['reason_code'] ?? null),
                    'reason_note' => $line['note'] ?? null,
                ]);

                $subtotal += (float) $item->price * $qty;
            }

            $rma->update(['items_subtotal' => round($subtotal, 2)]);
            $this->event($rma, null, 'requested', 'user', $rma->created_by_user_id);

            return $rma->fresh('items');
        });
    }

    /**
     * Approve the return. Approved quantities default to requested; pass [returnItemId => qty] to
     * approve fewer. Sets the refund total from the approved lines.
     *
     * @param array<int, int> $approvedQuantities
     */
    public function approve(ReturnRequest $rma, array $approvedQuantities = [], ?int $userId = null): ReturnRequest
    {
        if ($rma->is_marketplace_managed) {
            throw new \RuntimeException('marketplace_managed_cannot_be_approved');
        }

        return DB::transaction(function () use ($rma, $approvedQuantities, $userId) {
            $anyApproved = false;
            foreach ($rma->items as $item) {
                $qty = $approvedQuantities[$item->id] ?? $item->quantity_requested;
                $qty = min((int) $qty, (int) $item->quantity_requested);
                $item->update(['quantity_approved' => $qty]);
                $anyApproved = $anyApproved || $qty > 0;
            }

            if (! $anyApproved) {
                throw new \RuntimeException('approve_requires_at_least_one_line');
            }

            $this->recomputeRefund($rma);
            $rma->forceFill([
                'resolution' => $rma->resolution === 'none' ? 'refund' : $rma->resolution,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ])->save();

            $this->transition($rma, 'approved', 'user', $userId);

            return $rma->fresh('items');
        });
    }

    public function reject(ReturnRequest $rma, string $reason, ?int $userId = null): ReturnRequest
    {
        return DB::transaction(function () use ($rma, $reason, $userId) {
            $rma->forceFill(['rejected_reason' => $reason, 'rejected_at' => now()])->save();
            $this->transition($rma, 'rejected', 'user', $userId, $reason);

            return $rma;
        });
    }

    /** Customer handed the parcel over (approved → in_transit). */
    public function ship(ReturnRequest $rma): ReturnRequest
    {
        return DB::transaction(function () use ($rma) {
            $rma->forceFill(['shipped_at' => now()])->save();
            $this->transition($rma, 'in_transit', 'customer', null);

            return $rma;
        });
    }

    /**
     * Parcel arrived (in_transit → received). Received quantities default to approved.
     *
     * @param array<int, int> $receivedQuantities
     */
    public function receive(ReturnRequest $rma, array $receivedQuantities = []): ReturnRequest
    {
        return DB::transaction(function () use ($rma, $receivedQuantities) {
            foreach ($rma->items as $item) {
                $qty = $receivedQuantities[$item->id] ?? $item->quantity_approved;
                $item->update([
                    'quantity_received' => min((int) $qty, (int) $item->quantity_approved),
                    'received_at' => now(),
                ]);
            }
            $rma->forceFill(['received_at' => now()])->save();
            $this->transition($rma, 'received', 'user', null);

            return $rma->fresh('items');
        });
    }

    /**
     * Grade one received line. A `restock` disposition writes the quantity back into stock and logs
     * it; `scrap` records the write-off without restoring stock. When every line is graded the RMA
     * moves received → inspecting → inspected.
     */
    public function inspectLine(
        ReturnItem $item,
        string $condition,
        string $disposition,
        int $quantityRestock = 0,
        int $quantityScrap = 0,
        ?string $note = null,
    ): ReturnItem {
        return DB::transaction(function () use ($item, $condition, $disposition, $quantityRestock, $quantityScrap, $note) {
            $rma = $item->returnRequest;

            if ($quantityRestock + $quantityScrap > (int) $item->quantity_received) {
                throw new \RuntimeException('graded_quantity_exceeds_received');
            }

            // Move received → inspecting on the first graded line.
            if ($rma->status === 'received') {
                $this->transition($rma, 'inspecting', 'user', null);
                $rma->refresh();
            }

            $inventoryLogId = null;
            if ($disposition === 'restock' && $quantityRestock > 0) {
                $inventoryLogId = $this->restock($item, $quantityRestock);
            }

            // Reverse the COGS in the FIFO ledger so profit reflects the return: a restock recovers
            // the cost (added back), a scrap is a write-off (lost). Best-effort — an order that was
            // never costed (no consumption) simply has nothing to reverse.
            if ($orderItem = $item->orderItem) {
                try {
                    if ($quantityRestock > 0) {
                        $this->ledger->reverse($orderItem, $quantityRestock, true);
                    }
                    if ($quantityScrap > 0) {
                        $this->ledger->reverse($orderItem, $quantityScrap, false);
                    }
                } catch (\Throwable $e) {
                    // no consumption to reverse — leave COGS as-is
                }
            }

            $item->update([
                'condition' => $condition,
                'disposition' => $disposition,
                'quantity_restocked' => $quantityRestock,
                'quantity_scrapped' => $quantityScrap,
                'inspection_note' => $note,
                'inventory_log_id' => $inventoryLogId,
                'inspected_at' => now(),
            ]);

            // All lines graded ⇒ inspected.
            if ($rma->items()->where('disposition', 'pending')->doesntExist()) {
                $rma->forceFill(['inspected_at' => now()])->save();
                $this->transition($rma->fresh(), 'inspected', 'user', null);
            }

            return $item->fresh();
        });
    }

    /** Put a restocked quantity back into stock and record it in the inventory ledger. */
    private function restock(ReturnItem $item, int $quantity): int
    {
        $orderItem = $item->orderItem;
        $variant = $orderItem?->sku
            ? \App\Models\ProductVariant::where('sku', $orderItem->sku)->first()
            : null;

        $log = InventoryLog::create([
            'product_id' => $variant?->product_id,
            'product_variant_id' => $variant?->id,
            'change' => $quantity,
            'source' => 'return',
            'reason' => 'Return restock — RMA '.$item->returnRequest->rma_number,
        ]);

        if ($variant) {
            $variant->increment('stock', $quantity);
        }

        return $log->id;
    }

    /**
     * Issue the refund (spec §4.4): re-derive the amount, record a refund row, accumulate it on the
     * RMA, and move inspected → refund_pending → refunded. A return with nothing to refund
     * (marketplace-issued, RTO, or zero total) closes instead. Recomputes the order's profit so the
     * refunded revenue and recovered/lost COGS land in the P&L.
     */
    public function refund(ReturnRequest $rma, string $method = 'original_payment', ?int $userId = null): ReturnRequest
    {
        // Can this channel push the refund itself? Shopify/Woo refund over REST; a local-only
        // platform (marketplace mirrors are M4) is settled here and reconciled out-of-band.
        $pushable = $this->canPushRefund($rma);

        $pushRefundId = DB::transaction(function () use ($rma, $method, $userId, $pushable) {
            $calc = $this->refundCalculator->compute($rma);
            foreach ($calc['lines'] as $itemId => $amount) {
                ReturnItem::whereKey($itemId)->update(['refund_amount' => $amount]);
            }
            $rma->forceFill([
                'items_subtotal' => $calc['items_subtotal'],
                'tax_refund' => $calc['tax_refund'],
                'total_refund' => $calc['total_refund'],
            ])->save();

            // Nothing owed → close (still recording the reason it was a no-op).
            if ($calc['total_refund'] <= 0 || $rma->refund_responsibility === 'marketplace' || $rma->type === 'rto') {
                $rma->forceFill(['closed_at' => now()])->save();
                $this->transition($rma, 'closed', 'system', $userId, 'no_refund_due');
                CalculateOrderProfitJob::dispatch($rma->order_id);

                return null;
            }

            $refund = Refund::create([
                'organization_id' => $rma->organization_id,
                'store_id' => $rma->store_id,
                'order_id' => $rma->order_id,
                'return_request_id' => $rma->id,
                'issuer' => 'merchant',
                'method' => $method,
                // Always born pending; settleRefund() flips it to succeeded once the money is real —
                // immediately for a local channel, or after the platform confirms for a pushable one.
                'status' => 'pending',
                'amount' => $calc['total_refund'],
                'items_amount' => $calc['items_subtotal'],
                'tax_amount' => $calc['tax_refund'],
                'shipping_amount' => $rma->shipping_refund,
                'currency' => $rma->currency,
                'gateway' => $pushable ? $rma->store->platform : 'manual',
                'idempotency_key' => sha1('rma:'.$rma->id.':'.$calc['total_refund']),
                'processed_at' => null,
                'created_by_user_id' => $userId,
            ]);

            $rma->forceFill(['refunded_amount' => $calc['total_refund']])->save();
            $this->transition($rma, 'refund_pending', 'user', $userId);

            // Local channel: settle in-transaction (money moved out-of-band). Pushable channel: leave
            // it pending and hand the id back so the job is dispatched *after* this commit — the job
            // must never read the refund row before it exists.
            if (! $pushable) {
                $this->settleRefund($refund, null);

                return null;
            }

            return $refund->id;
        });

        if ($pushRefundId !== null) {
            IssueRefundJob::dispatch($pushRefundId);
        }

        return $rma->fresh('items');
    }

    /** Does the store's platform advertise a refund-push capability? */
    private function canPushRefund(ReturnRequest $rma): bool
    {
        try {
            $service = IntegrationFactory::make((string) $rma->store->platform);
        } catch (\Throwable $e) {
            return false;
        }

        return $service instanceof SupportsReturnsInterface
            && $service->supportsReturnCapability('refund')
            && $rma->store->integration !== null;
    }

    /**
     * Mark a refund settled (money has moved) and complete the RMA. Idempotent: a second call after
     * the refund already succeeded is a no-op, so a job retry never re-posts the P&L twice.
     */
    public function settleRefund(Refund $refund, ?string $externalId): void
    {
        if ($refund->status === 'succeeded') {
            return;
        }

        DB::transaction(function () use ($refund, $externalId) {
            $refund->forceFill([
                'status' => 'succeeded',
                'external_id' => $externalId ?? $refund->external_id,
                'processed_at' => now(),
                'failure_reason' => null,
            ])->save();

            $rma = $refund->returnRequest;
            if ($rma && $rma->status === 'refund_pending') {
                $rma->forceFill(['refunded_at' => now()])->save();
                $this->transition($rma, 'refunded', 'system', null,
                    $externalId ? 'platform_refund:'.$externalId : null);
            }

            // Only now is the refund real money — post the refunded revenue + recovered/lost COGS.
            CalculateOrderProfitJob::dispatch($refund->order_id);
        });
    }

    /** Record a failed push attempt without rolling back the local decision (spec §5.4). */
    public function failRefund(Refund $refund, string $reason): void
    {
        $refund->forceFill([
            'status' => 'failed',
            'attempts' => (int) $refund->attempts + 1,
            'failure_reason' => $reason,
        ])->save();

        if ($rma = $refund->returnRequest) {
            $this->event($rma, $rma->status, $rma->status, 'system', null, 'refund_push_failed:'.$reason);
        }
    }

    /** Preview the refund total at approval time (uses approved quantities). */
    private function recomputeRefund(ReturnRequest $rma): void
    {
        $calc = $this->refundCalculator->compute($rma);
        foreach ($calc['lines'] as $itemId => $amount) {
            ReturnItem::whereKey($itemId)->update(['refund_amount' => $amount]);
        }
        $rma->update([
            'items_subtotal' => $calc['items_subtotal'],
            'tax_refund' => $calc['tax_refund'],
            'total_refund' => $calc['total_refund'],
        ]);
    }

    /** Validated status change + audit row, in one transaction. */
    private function transition(ReturnRequest $rma, string $to, string $actorType, ?int $actorId, ?string $note = null): void
    {
        $from = $rma->status;
        ReturnStateMachine::assert($from, $to);

        $rma->update(['status' => $to]);
        $this->event($rma, $from, $to, $actorType, $actorId, $note);
    }

    private function event(ReturnRequest $rma, ?string $from, string $to, string $actorType, ?int $actorId, ?string $note = null): void
    {
        ReturnEvent::create([
            'return_request_id' => $rma->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'note' => $note,
        ]);
    }

    private function quantityAlreadyReturned(int $orderItemId, int $excludeRmaId): int
    {
        return (int) ReturnItem::where('order_item_id', $orderItemId)
            ->where('return_request_id', '!=', $excludeRmaId)
            ->whereHas('returnRequest', fn ($q) => $q->whereNotIn('status', ['rejected', 'cancelled']))
            ->sum('quantity_approved');
    }

    /** RMA-YYYY-NNNNNN, sequential per org. */
    private function nextRmaNumber(int $organizationId): string
    {
        $seq = ReturnRequest::where('organization_id', $organizationId)->count() + 1;

        return 'RMA-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
