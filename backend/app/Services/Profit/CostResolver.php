<?php

namespace App\Services\Profit;

use App\Models\ProductCost;
use Carbon\CarbonInterface;

/**
 * Resolves the landed unit cost that applies to a SKU at a point in time (spec 01 §4.2).
 *
 * Resolution order, most specific first:
 *   1. a per-store override        (organization_id, sku, store_id = $storeId)
 *   2. an org-wide cost            (organization_id, sku, store_id IS NULL)
 *   3. otherwise ResolvedCost::missing() — we never invent a number.
 *
 * Always keyed on (organization_id, sku), never SKU alone: product_variants.sku is currently
 * globally unique across tenants, so a SKU-only lookup could read another org's cost.
 */
class CostResolver
{
    public function resolve(
        int $organizationId,
        ?string $sku,
        ?int $storeId,
        CarbonInterface $at,
    ): ResolvedCost {
        // SKU-less lines can't be costed at all; they surface as unmatched in the coverage report.
        if ($sku === null || $sku === '') {
            return ResolvedCost::missing();
        }

        if ($storeId !== null) {
            $override = $this->query($organizationId, $sku, $at)
                ->where('store_id', $storeId)
                ->first();

            if ($override) {
                return $this->toResolved($override);
            }
        }

        $orgWide = $this->query($organizationId, $sku, $at)
            ->whereNull('store_id')
            ->first();

        return $orgWide ? $this->toResolved($orgWide) : ResolvedCost::missing();
    }

    /**
     * Costs in force at `$at`: effective on or before that date, and either still open or not
     * yet superseded. Newest `valid_from` wins, with the id as a deterministic tiebreak when
     * two rows share a date.
     */
    private function query(int $organizationId, string $sku, CarbonInterface $at)
    {
        return ProductCost::query()
            ->where('organization_id', $organizationId)
            ->where('sku', $sku)
            ->whereDate('valid_from', '<=', $at->toDateString())
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_to')->orWhereDate('valid_to', '>', $at->toDateString());
            })
            ->orderByDesc('valid_from')
            ->orderByDesc('id');
    }

    private function toResolved(ProductCost $cost): ResolvedCost
    {
        return ResolvedCost::found(
            landedUnitCostBase: (string) $cost->landed_unit_cost_base,
            method: $cost->method,
            currency: $cost->currency,
            sourceId: $cost->id,
            isEstimated: $cost->source === 'estimated',
        );
    }
}
