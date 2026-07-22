<?php

namespace App\Observers;

use App\Models\ProductCost;

/**
 * Keeps derived cost fields correct (spec 01 §5.1).
 *
 * `landed_unit_cost` is maintained here rather than as a generated column because sqlite (tests)
 * and MySQL disagree on `storedAs` syntax — an observer behaves identically on both.
 */
class ProductCostObserver
{
    public function saving(ProductCost $cost): void
    {
        $cost->landed_unit_cost = $cost->computeLandedUnitCost();
        $cost->landed_unit_cost_base = $this->toBase(
            $cost->landed_unit_cost,
            $cost->fx_rate_to_base ?? 1
        );
    }

    /**
     * Close off the cost row this one supersedes, so exactly one definition is in force for a
     * given (org, sku, store) at any date. Without this, CostResolver's "newest valid_from wins"
     * would still work, but historical recalculation would see overlapping windows.
     */
    public function saved(ProductCost $cost): void
    {
        ProductCost::query()
            ->where('organization_id', $cost->organization_id)
            ->where('sku', $cost->sku)
            ->when(
                $cost->store_id === null,
                fn ($q) => $q->whereNull('store_id'),
                fn ($q) => $q->where('store_id', $cost->store_id)
            )
            ->whereKeyNot($cost->id)
            ->whereDate('valid_from', '<', $cost->valid_from)
            ->where(function ($q) use ($cost) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>', $cost->valid_from);
            })
            ->update(['valid_to' => $cost->valid_from]);
    }

    /** Exact 4 dp conversion via integer minor units — no float drift, no ext-bcmath. */
    private function toBase(string|float|int|null $amount, string|float|int $rate): string
    {
        $scaled = (int) round(((float) $amount) * (float) $rate * 10000);
        $sign = $scaled < 0 ? '-' : '';
        $abs = abs($scaled);

        return sprintf('%s%d.%04d', $sign, intdiv($abs, 10000), $abs % 10000);
    }
}
