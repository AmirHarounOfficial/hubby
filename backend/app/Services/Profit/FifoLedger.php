<?php

namespace App\Services\Profit;

use App\Models\CostLayer;
use App\Models\CostLayerConsumption;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * Consumes and reverses FIFO cost layers, writing the COGS ledger (spec 01 §4.3).
 *
 * Every write is keyed by a deterministic `consumption_key`, so re-running a calculation is a
 * no-op rather than double-charging COGS.
 */
class FifoLedger
{
    public function __construct(private readonly CostResolver $costs)
    {
    }

    /**
     * Recognise COGS for an order line.
     *
     * Idempotent: the outstanding quantity is derived from what has already been consumed, so
     * calling this repeatedly converges rather than accumulating.
     */
    public function consume(OrderItem $item): void
    {
        DB::transaction(function () use ($item) {
            $order = $item->order;
            $store = $order?->store;

            if (! $order || ! $store) {
                return;
            }

            $organizationId = (int) $store->organization_id;
            // `placed_at` is the marketplace order date. Until that column lands (deferred —
            // it belongs to the SyncOrdersJob change), fall back to our insert timestamp.
            $orderedAt = $order->placed_at ?? $order->created_at;

            // Outstanding = ordered quantity minus what we have already recognised as SOLD.
            // Deliberately counts only positive rows: a refund reversal must not make the line
            // look under-consumed and trigger a second consumption.
            $alreadyConsumed = (int) CostLayerConsumption::query()
                ->where('order_item_id', $item->id)
                ->where('qty', '>', 0)
                ->sum('qty');

            $qtyNeeded = (int) $item->quantity - $alreadyConsumed;

            if ($qtyNeeded <= 0) {
                return;
            }

            $layers = CostLayer::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $item->sku)
                ->where(function ($q) use ($store) {
                    $q->whereNull('store_id')->orWhere('store_id', $store->id);
                })
                ->where('qty_remaining', '>', 0)
                ->where('acquired_at', '<=', $orderedAt)
                ->orderBy('acquired_at')
                ->orderBy('id')
                // No-op on sqlite, but this is what makes concurrent order processing safe on
                // MySQL: without it two workers both read qty_remaining and both consume it.
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if ($qtyNeeded <= 0) {
                    break;
                }

                $take = min($qtyNeeded, (int) $layer->qty_remaining);

                $this->record($item, $layer, $take, CostLayerConsumption::REASON_SALE, $orderedAt);

                $layer->decrement('qty_remaining', $take);
                $qtyNeeded -= $take;
            }

            if ($qtyNeeded > 0) {
                $this->consumeShortfall($item, $organizationId, $store->id, $orderedAt, $qtyNeeded);
            }
        });
    }

    /**
     * Reverse recognised COGS when units come back.
     *
     * Walks the line's consumptions newest-first (LIFO of consumption — the units taken most
     * recently are the ones returning). If the goods are resellable the quantity goes back onto
     * its original layer at its original cost; if not, the layer is left depleted and the value
     * is a genuine loss, which the P&L then shows as such instead of silently absorbing it.
     */
    public function reverse(OrderItem $item, int $qty, bool $restocked): void
    {
        if ($qty <= 0) {
            return;
        }

        DB::transaction(function () use ($item, $qty, $restocked) {
            $remaining = $qty;

            $consumptions = CostLayerConsumption::query()
                ->where('order_item_id', $item->id)
                ->where('qty', '>', 0)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            foreach ($consumptions as $consumption) {
                if ($remaining <= 0) {
                    break;
                }

                // How much of this consumption is still un-reversed?
                $alreadyReversed = (int) abs(
                    CostLayerConsumption::query()
                        ->where('reversal_of_id', $consumption->id)
                        ->sum('qty')
                );

                $reversible = (int) $consumption->qty - $alreadyReversed;

                if ($reversible <= 0) {
                    continue;
                }

                $take = min($remaining, $reversible);

                CostLayerConsumption::firstOrCreate(
                    [
                        'organization_id' => $consumption->organization_id,
                        'consumption_key' => CostLayerConsumption::reversalKey($consumption->id),
                    ],
                    [
                        'cost_layer_id' => $consumption->cost_layer_id,
                        'order_id' => $consumption->order_id,
                        'order_item_id' => $consumption->order_item_id,
                        'qty' => -$take,
                        'unit_cost_base' => $consumption->unit_cost_base,
                        'amount_base' => $this->multiply($consumption->unit_cost_base, -$take),
                        'consumed_at' => now(),
                        'reason' => $restocked
                            ? CostLayerConsumption::REASON_REFUND_RESTOCK
                            : CostLayerConsumption::REASON_REFUND_WRITEOFF,
                        'reversal_of_id' => $consumption->id,
                    ]
                );

                if ($restocked) {
                    CostLayer::whereKey($consumption->cost_layer_id)->increment('qty_remaining', $take);
                }

                $remaining -= $take;
            }
        });
    }

    /**
     * No layer covers these units — the merchant sold stock we have no purchase record for.
     *
     * Fall back to the resolved cost definition and synthesise an estimated layer so the COGS is
     * still attributable and visibly flagged. If even that is missing we record nothing: an
     * invented number is worse than an obvious gap.
     */
    private function consumeShortfall(
        OrderItem $item,
        int $organizationId,
        int $storeId,
        $orderedAt,
        int $qty,
    ): void {
        $fallback = $this->costs->resolve($organizationId, $item->sku, $storeId, $orderedAt);

        if ($fallback->isMissing) {
            return;
        }

        $layer = CostLayer::create([
            'organization_id' => $organizationId,
            'sku' => $item->sku,
            'store_id' => null,
            'source' => 'estimated',
            'is_estimated' => true,
            'acquired_at' => $orderedAt,
            'qty_received' => $qty,
            // Synthesised purely to carry a cost; it must never be available to later orders.
            'qty_remaining' => 0,
            'unit_cost' => $fallback->landedUnitCostBase,
            'unit_cost_base' => $fallback->landedUnitCostBase,
            'currency' => $fallback->currency ?? 'SAR',
        ]);

        $this->record($item, $layer, $qty, CostLayerConsumption::REASON_SALE, $orderedAt);
    }

    private function record(OrderItem $item, CostLayer $layer, int $qty, string $reason, $consumedAt): void
    {
        CostLayerConsumption::firstOrCreate(
            [
                'organization_id' => $layer->organization_id,
                'consumption_key' => CostLayerConsumption::saleKey($item->id, $layer->id),
            ],
            [
                'cost_layer_id' => $layer->id,
                'order_id' => $item->order_id,
                'order_item_id' => $item->id,
                'qty' => $qty,
                'unit_cost_base' => $layer->unit_cost_base,
                'amount_base' => $this->multiply($layer->unit_cost_base, $qty),
                'consumed_at' => $consumedAt,
                'reason' => $reason,
            ]
        );
    }

    /** Exact 4 dp multiplication via integer minor units — no float drift, no ext-bcmath. */
    private function multiply(string|float|int|null $unitCostBase, int $qty): string
    {
        $scaled = (int) round(((float) $unitCostBase) * 10000) * $qty;
        $sign = $scaled < 0 ? '-' : '';
        $abs = abs($scaled);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, 10000), $abs % 10000);
    }
}
