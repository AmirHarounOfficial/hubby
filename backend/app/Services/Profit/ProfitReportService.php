<?php

namespace App\Services\Profit;

use App\Models\OrderItemProfit;
use App\Models\OrderProfit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read queries for the profit endpoints (spec 01 §5.5).
 *
 * Everything reads the materialized `order_profits` / `order_item_profits` rollups — no profit is
 * recomputed on request, which is what keeps a 90-day P&L fast and makes the numbers reproducible.
 *
 * Every response carries coverage metadata. A margin figure without knowing how much of it is
 * estimated or missing cost is exactly the kind of number that gets trusted when it shouldn't be.
 */
class ProfitReportService
{
    public function summary(int $organizationId, string $from, string $to, ?int $storeId = null): array
    {
        $base = $this->scope($organizationId, $from, $to, $storeId);

        $totals = (clone $base)->selectRaw(
            'COUNT(*) as orders,
             COALESCE(SUM(gross_revenue_base), 0) as gross_revenue,
             COALESCE(SUM(net_revenue_base), 0) as net_revenue,
             COALESCE(SUM(vat_base), 0) as vat,
             COALESCE(SUM(cogs_base), 0) as cogs,
             COALESCE(SUM(total_fees_base), 0) as fees,
             COALESCE(SUM(ad_spend_base), 0) as ad_spend,
             COALESCE(SUM(refund_cogs_base), 0) as refund_cogs,
             COALESCE(SUM(lost_cogs_base), 0) as lost_cogs,
             COALESCE(SUM(net_profit_base), 0) as net_profit'
        )->first();

        $netRevenue = (string) ($totals->net_revenue ?? 0);
        // Order-level profit (before period-level advertising and operating expenses, which aren't
        // attributable to a single order — the P&L's last two lines, spec 01 §7.1).
        $operatingProfit = (string) ($totals->net_profit ?? 0);

        $adSpend = $this->adSpendTotal($organizationId, $from, $to, $storeId);
        $expenses = $this->expenseTotal($organizationId, $from, $to, $storeId);

        // NET PROFIT = operating profit − advertising − operating expenses.
        $netProfit = Money::subtract(Money::subtract($operatingProfit, $adSpend), $expenses);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'orders' => (int) ($totals->orders ?? 0),
            'gross_revenue' => Money::sum($totals->gross_revenue ?? 0),
            'net_revenue' => Money::sum($netRevenue),
            'vat' => Money::sum($totals->vat ?? 0),
            'cogs' => Money::sum($totals->cogs ?? 0),
            'fees' => Money::sum($totals->fees ?? 0),
            'ad_spend' => Money::sum($adSpend),
            'expenses' => Money::sum($expenses),
            'operating_profit' => Money::sum($operatingProfit),
            'refund_cogs' => Money::sum($totals->refund_cogs ?? 0),
            'lost_cogs' => Money::sum($totals->lost_cogs ?? 0),
            'net_profit' => Money::sum($netProfit),
            'margin_pct' => Money::ratio($netProfit, $netRevenue),
            'coverage' => $this->coverage($organizationId, $from, $to, $storeId),
        ];
    }

    public function timeline(int $organizationId, string $from, string $to, ?int $storeId = null): array
    {
        return $this->scope($organizationId, $from, $to, $storeId)
            ->selectRaw(
                'placed_on as date,
                 COUNT(*) as orders,
                 COALESCE(SUM(net_revenue_base), 0) as net_revenue,
                 COALESCE(SUM(cogs_base), 0) as cogs,
                 COALESCE(SUM(total_fees_base), 0) as fees,
                 COALESCE(SUM(net_profit_base), 0) as net_profit'
            )
            ->groupBy('placed_on')
            ->orderBy('placed_on')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'orders' => (int) $row->orders,
                'net_revenue' => Money::sum($row->net_revenue),
                'cogs' => Money::sum($row->cogs),
                'fees' => Money::sum($row->fees),
                'net_profit' => Money::sum($row->net_profit),
            ])
            ->all();
    }

    /** Per-SKU profit — the view that shows which products actually make money. */
    public function bySku(int $organizationId, string $from, string $to, ?int $storeId = null, int $limit = 50): array
    {
        return OrderItemProfit::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('placed_on', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->selectRaw(
                'sku,
                 SUM(quantity) as units,
                 COALESCE(SUM(net_revenue_base), 0) as net_revenue,
                 COALESCE(SUM(cogs_base), 0) as cogs,
                 COALESCE(SUM(direct_fees_base + allocated_fees_base), 0) as fees,
                 COALESCE(SUM(net_profit_base), 0) as net_profit,
                 MAX(CASE WHEN is_estimated THEN 1 ELSE 0 END) as is_estimated'
            )
            ->groupBy('sku')
            ->orderByRaw('SUM(net_profit_base) DESC')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $units = (int) $row->units;

                return [
                    'sku' => $row->sku,
                    'units' => $units,
                    'net_revenue' => Money::sum($row->net_revenue),
                    'cogs' => Money::sum($row->cogs),
                    'fees' => Money::sum($row->fees),
                    'net_profit' => Money::sum($row->net_profit),
                    'profit_per_unit' => $units > 0
                        ? Money::fromMinor((int) round(Money::toMinor($row->net_profit) / $units))
                        : null,
                    'margin_pct' => Money::ratio($row->net_profit, $row->net_revenue),
                    'is_estimated' => (bool) $row->is_estimated,
                ];
            })
            ->all();
    }

    public function byChannel(int $organizationId, string $from, string $to): array
    {
        return OrderProfit::query()
            ->where('order_profits.organization_id', $organizationId)
            ->whereBetween('placed_on', [$from, $to])
            ->join('stores', 'stores.id', '=', 'order_profits.store_id')
            ->selectRaw(
                'stores.id as store_id, stores.name as store_name, stores.platform as platform,
                 COUNT(*) as orders,
                 COALESCE(SUM(net_revenue_base), 0) as net_revenue,
                 COALESCE(SUM(cogs_base), 0) as cogs,
                 COALESCE(SUM(total_fees_base), 0) as fees,
                 COALESCE(SUM(net_profit_base), 0) as net_profit'
            )
            ->groupBy('stores.id', 'stores.name', 'stores.platform')
            ->orderByRaw('SUM(net_profit_base) DESC')
            ->get()
            ->map(fn ($row) => [
                'store_id' => (int) $row->store_id,
                'store_name' => $row->store_name,
                'platform' => $row->platform,
                'orders' => (int) $row->orders,
                'net_revenue' => Money::sum($row->net_revenue),
                'cogs' => Money::sum($row->cogs),
                'fees' => Money::sum($row->fees),
                'net_profit' => Money::sum($row->net_profit),
                'margin_pct' => Money::ratio($row->net_profit, $row->net_revenue),
            ])
            ->all();
    }

    /**
     * How trustworthy is the number above?
     *
     * Surfaced alongside every figure on purpose: a margin built on 60% estimated fees and
     * missing costs is a different claim from one built on settled data, and the merchant is
     * entitled to know which they are looking at.
     */
    public function coverage(int $organizationId, string $from, string $to, ?int $storeId = null): array
    {
        $row = $this->scope($organizationId, $from, $to, $storeId)
            ->selectRaw(
                'COUNT(*) as total,
                 SUM(CASE WHEN missing_cost THEN 1 ELSE 0 END) as missing_cost,
                 SUM(CASE WHEN is_estimated THEN 1 ELSE 0 END) as estimated'
            )
            ->first();

        $total = (int) ($row->total ?? 0);
        $missing = (int) ($row->missing_cost ?? 0);
        $estimated = (int) ($row->estimated ?? 0);

        $skusMissing = OrderItemProfit::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('placed_on', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereNotNull('sku')
            ->where('cogs_base', 0)
            ->distinct()
            ->limit(25)
            ->pluck('sku')
            ->all();

        return [
            'orders_total' => $total,
            'orders_missing_cost' => $missing,
            'orders_estimated' => $estimated,
            'cost_coverage_pct' => $total > 0 ? round(($total - $missing) / $total, 4) : null,
            'skus_missing_cost' => $skusMissing,
        ];
    }

    public function forOrder(int $organizationId, int $orderId): ?array
    {
        $profit = OrderProfit::query()
            ->where('organization_id', $organizationId)
            ->where('order_id', $orderId)
            ->first();

        if (! $profit) {
            return null;
        }

        $lines = OrderItemProfit::query()
            ->where('order_id', $orderId)
            ->get()
            ->map(fn ($l) => [
                'order_item_id' => $l->order_item_id,
                'sku' => $l->sku,
                'quantity' => $l->quantity,
                'net_revenue' => Money::sum($l->net_revenue_base),
                'cogs' => Money::sum($l->cogs_base),
                'direct_fees' => Money::sum($l->direct_fees_base),
                'allocated_fees' => Money::sum($l->allocated_fees_base),
                'net_profit' => Money::sum($l->net_profit_base),
                'margin_pct' => $l->margin_pct !== null ? (float) $l->margin_pct : null,
                'is_estimated' => (bool) $l->is_estimated,
            ])
            ->all();

        return ['order' => $this->shapeOrder($profit), 'lines' => $lines];
    }

    /**
     * Shaped by hand rather than `toArray()`: MySQL hands decimals back as strings and SQLite as
     * floats, and a money field that silently changes type with the driver is a bug waiting to
     * surface in the client. Everything monetary leaves here as a 4-dp string.
     */
    private function shapeOrder(OrderProfit $p): array
    {
        return [
            'order_id' => (int) $p->order_id,
            'store_id' => (int) $p->store_id,
            'placed_on' => $p->placed_on?->toDateString(),
            'base_currency' => $p->base_currency,
            'gross_revenue_base' => Money::sum($p->gross_revenue_base),
            'discounts_base' => Money::sum($p->discounts_base),
            'shipping_revenue_base' => Money::sum($p->shipping_revenue_base),
            'net_revenue_base' => Money::sum($p->net_revenue_base),
            'vat_base' => Money::sum($p->vat_base),
            'cogs_base' => Money::sum($p->cogs_base),
            'total_fees_base' => Money::sum($p->total_fees_base),
            'fees_by_type' => $p->fees_by_type ?? [],
            'ad_spend_base' => Money::sum($p->ad_spend_base),
            'refund_revenue_base' => Money::sum($p->refund_revenue_base),
            'refund_cogs_base' => Money::sum($p->refund_cogs_base),
            'lost_cogs_base' => Money::sum($p->lost_cogs_base),
            'net_profit_base' => Money::sum($p->net_profit_base),
            'margin_pct' => $p->margin_pct !== null ? (float) $p->margin_pct : null,
            'is_estimated' => (bool) $p->is_estimated,
            'estimated_share' => $p->estimated_share !== null ? (float) $p->estimated_share : null,
            'missing_cost' => (bool) $p->missing_cost,
            'computed_at' => $p->computed_at?->toIso8601String(),
        ];
    }

    /** Default window: the last 30 days, inclusive. */
    public static function defaultRange(?string $from, ?string $to): array
    {
        $end = $to ? Carbon::parse($to) : Carbon::now();
        $start = $from ? Carbon::parse($from) : (clone $end)->subDays(29);

        return [$start->toDateString(), $end->toDateString()];
    }

    /** Advertising spend in the period (spec 01 §7.1 "Advertising" line). */
    private function adSpendTotal(int $organizationId, string $from, string $to, ?int $storeId): string
    {
        $total = DB::table('ad_spend')
            ->where('organization_id', $organizationId)
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->sum('spend_base');

        return (string) ($total ?? 0);
    }

    /**
     * Operating expenses in the period, summed from the materialized daily allocations (never the
     * recurrence rules). A store filter counts only expenses pinned to that store; org-level P&L
     * counts everything.
     */
    private function expenseTotal(int $organizationId, string $from, string $to, ?int $storeId): string
    {
        $total = DB::table('expense_allocations')
            ->where('organization_id', $organizationId)
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->sum('amount_base');

        return (string) ($total ?? 0);
    }

    private function scope(int $organizationId, string $from, string $to, ?int $storeId)
    {
        return DB::table('order_profits')
            ->where('organization_id', $organizationId)
            ->whereBetween('placed_on', [$from, $to])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));
    }
}
