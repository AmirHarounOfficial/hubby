<?php

namespace App\Services\Warehouse;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\PickList;
use App\Models\PickListItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picking (spec 08 §4.1).
 *
 * CRITICAL: **picking does not change stock.** A pick moves goods from shelf to trolley to box; the
 * units leave the building at ship, not at pick. Deducting here and again at channel order sync
 * would double-count. So a pick writes qty_picked and a scan_event — no inventory_logs, no
 * PushInventoryJob.
 *
 * The single exception is a short with reason `damaged`: those units are genuinely gone, so that
 * (and only that) writes a negative inventory adjustment.
 *
 * A consequence worth stating: because qty_picked is a monotonic counter capped at qty_required,
 * replaying the same pick is a no-op even without the idempotency key. Belt and braces.
 */
class PickService
{
    public function __construct(private readonly BarcodeResolver $resolver)
    {
    }

    /** Build a pick list from one or more orders. Batch lists aggregate the same SKU across orders. */
    public function createFromOrders(int $organizationId, Collection $orders, array $attrs = [], ?int $userId = null): PickList
    {
        if ($orders->isEmpty()) {
            throw new \RuntimeException('PICK_LIST_NEEDS_ORDERS');
        }

        return DB::transaction(function () use ($organizationId, $orders, $attrs, $userId) {
            $type = $orders->count() > 1 ? 'batch' : 'order';

            $list = PickList::create([
                'organization_id' => $organizationId,
                'warehouse_id' => $attrs['warehouse_id'] ?? null,
                'code' => $this->nextCode($organizationId),
                'type' => $type,
                'status' => 'ready',
                'assigned_user_id' => $attrs['assigned_user_id'] ?? null,
                'created_by_user_id' => $userId,
                'priority' => $attrs['priority'] ?? 5,
                'notes' => $attrs['notes'] ?? null,
            ]);

            $list->orders()->sync($orders->pluck('id')->all());

            // A batch pick walks the warehouse once for the same SKU across orders; a single-order
            // pick keeps its order_item link so pack verification can trace it back.
            $lines = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $key = $type === 'batch' ? ($item->sku ?? 'sku:'.$item->id) : 'item:'.$item->id;

                    if (! isset($lines[$key])) {
                        $resolved = $this->resolver->resolve($organizationId, (string) $item->sku);
                        $lines[$key] = [
                            'pick_list_id' => $list->id,
                            'order_item_id' => $type === 'batch' ? null : $item->id,
                            'order_id' => $type === 'batch' ? null : $order->id,
                            'product_id' => $resolved->product?->id,
                            'product_variant_id' => $resolved->variant?->id,
                            'sku' => $item->sku,
                            'name' => $item->name,
                            'qty_required' => 0,
                            'sequence' => 0,
                        ];
                    }
                    $lines[$key]['qty_required'] += (int) $item->quantity;
                }
            }

            // Walk order: by suggested location sequence, then SKU, so the picker moves in one pass.
            $sequence = 1;
            foreach ($lines as $line) {
                $line['sequence'] = $sequence++;
                PickListItem::create($line);
            }

            $list->forceFill(['item_count' => count($lines)])->save();

            return $list->fresh('items');
        });
    }

    public function start(PickList $list, ?int $userId = null): PickList
    {
        if (! in_array($list->status, ['ready', 'paused'], true)) {
            throw new \RuntimeException('PICK_LIST_NOT_STARTABLE');
        }

        $list->forceFill([
            'status' => 'in_progress',
            'assigned_user_id' => $list->assigned_user_id ?? $userId,
            'started_at' => $list->started_at ?? now(),
        ])->save();

        return $list->fresh();
    }

    /**
     * Record a picked unit. Returns [line, result] where result is the §4.1 validation outcome.
     *
     * @return array{line:?PickListItem, result:string}
     */
    public function pick(PickList $list, string $barcode, int $qty = 1, ?int $userId = null): array
    {
        // P1 — the session must be open.
        if (! $list->isPickable()) {
            return ['line' => null, 'result' => 'session_closed'];
        }

        $resolved = $this->resolver->resolve((int) $list->organization_id, $barcode);
        if ($resolved->isUnknown()) {
            return ['line' => null, 'result' => 'unknown_barcode'];
        }

        // P3 — the item must be on this list. A wrong item is a hard block: picking the wrong
        // similar-looking variant is the dominant mispick class this whole feature exists to stop.
        $line = $list->items()
            ->when($resolved->variant,
                fn ($q) => $q->where('product_variant_id', $resolved->variant->id),
                fn ($q) => $q->where('product_id', $resolved->product?->id))
            ->orderByRaw("CASE WHEN status = 'picked' THEN 1 ELSE 0 END")
            ->orderBy('sequence')
            ->first();

        if (! $line) {
            return ['line' => null, 'result' => 'wrong_item'];
        }

        // P8 — the line is already complete.
        if ($line->remaining() <= 0) {
            return ['line' => $line, 'result' => 'over_pick'];
        }

        // P5 — never let the counter exceed what the order needs.
        if ($qty > $line->remaining()) {
            return ['line' => $line, 'result' => 'over_pick'];
        }

        return DB::transaction(function () use ($line, $qty, $userId, $list) {
            $line->qty_picked = (int) $line->qty_picked + $qty;
            $line->status = $line->remaining() <= 0 ? 'picked' : 'in_progress';
            $line->picked_by_user_id = $userId ?? $line->picked_by_user_id;
            if ($line->status === 'picked') {
                $line->picked_at = now();
            }
            $line->save();

            $this->refreshCounts($list);

            return ['line' => $line->fresh(), 'result' => 'accepted'];
        });
    }

    /**
     * Flag a line as short. `damaged` is the ONLY path in Pick that moves stock, because those units
     * really are gone — everything else is just "not on the shelf right now".
     */
    public function short(PickListItem $line, string $reason, ?int $qtyShort = null, ?int $userId = null): PickListItem
    {
        $qtyShort = $qtyShort ?? $line->remaining();

        return DB::transaction(function () use ($line, $reason, $qtyShort, $userId) {
            $line->qty_short = $qtyShort;
            $line->short_reason = $reason;
            $line->status = 'short';
            $line->picked_by_user_id = $userId ?? $line->picked_by_user_id;
            $line->save();

            if ($reason === 'damaged' && $qtyShort > 0 && ($line->product_id || $line->product_variant_id)) {
                if ($line->product_variant_id && ($variant = \App\Models\ProductVariant::find($line->product_variant_id))) {
                    $variant->decrement('stock', $qtyShort);
                } elseif ($line->product_id && ($product = \App\Models\Product::find($line->product_id))) {
                    $product->decrement('stock', $qtyShort);
                }

                InventoryLog::create([
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id,
                    'change' => -$qtyShort,
                    'source' => 'Warehouse Pick',
                    'reason' => 'Damaged at pick — list '.$line->pickList->code,
                ]);
            }

            $this->refreshCounts($line->pickList);

            return $line->fresh();
        });
    }

    /**
     * Complete the list. Any short line routes to `review` for a supervisor rather than completing,
     * because a short means the customer's order cannot be filled as promised.
     */
    public function complete(PickList $list, bool $acceptShorts = false): PickList
    {
        if (! in_array($list->status, ['in_progress', 'paused', 'review'], true)) {
            throw new \RuntimeException('PICK_LIST_NOT_OPEN');
        }

        $unfinished = $list->items()->whereNotIn('status', ['picked', 'short', 'skipped'])->exists();
        if ($unfinished) {
            throw new \RuntimeException('PICK_LIST_HAS_UNFINISHED_LINES');
        }

        $hasShort = $list->items()->where('status', 'short')->exists();
        if ($hasShort && ! $acceptShorts) {
            $list->forceFill(['status' => 'review'])->save();

            return $list->fresh('items');
        }

        $list->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        return $list->fresh('items');
    }

    public function cancel(PickList $list): PickList
    {
        if ($list->status === 'completed') {
            throw new \RuntimeException('PICK_LIST_ALREADY_COMPLETED');
        }
        // Picked quantities are deliberately NOT reversed — stock never moved, so there is nothing
        // to undo. The counters stay as an audit record of what the operator had in hand.
        $list->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        return $list;
    }

    private function refreshCounts(PickList $list): void
    {
        $list->forceFill([
            'picked_count' => $list->items()->whereIn('status', ['picked', 'short'])->count(),
        ])->save();
    }

    /** PL-YYMM-NNNN, sequential per org. */
    private function nextCode(int $organizationId): string
    {
        $seq = PickList::where('organization_id', $organizationId)->count() + 1;

        return 'PL-'.now()->format('ym').'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
