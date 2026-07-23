<?php

namespace App\Services\Returns;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\ReturnEvent;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;

/**
 * The RMA lifecycle (spec 03 §4). Slice 1 covers the operational core — create a return from an
 * order, approve per-line quantities, receive the parcel, and grade each line, with a restock
 * writing real inventory back (stock + an inventory_log) so returned goods actually re-enter stock.
 *
 * Every status change goes through transition(), which the state machine validates and which writes
 * a return_events audit row in the same transaction — the audit never lags the state.
 *
 * Deferred to later slices: refund posting into profit, RTO auto-detection (needs carrier tracking,
 * Spec 04), marketplace-managed mirrors, and the customer portal.
 */
class ReturnService
{
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

    /** total_refund = approved value + tax + shipping refund − restocking fee − customer-paid return leg. */
    private function recomputeRefund(ReturnRequest $rma): void
    {
        $itemsValue = 0.0;
        foreach ($rma->items as $item) {
            $lineRefund = round((float) $item->unit_price * (int) $item->quantity_approved, 2);
            $item->update(['refund_amount' => $lineRefund]);
            $itemsValue += $lineRefund;
        }

        $customerPaidReturn = $rma->return_shipping_paid_by === 'customer' ? (float) $rma->return_shipping_cost : 0.0;
        $total = $itemsValue + (float) $rma->tax_refund + (float) $rma->shipping_refund
            - (float) $rma->restocking_fee - $customerPaidReturn;

        $rma->update([
            'items_subtotal' => round($itemsValue, 2),
            'total_refund' => round(max(0, $total), 2),
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
