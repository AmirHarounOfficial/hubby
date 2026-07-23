<?php

namespace App\Services\Profit;

use App\Models\CostLayerConsumption;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\OrderItemProfit;
use App\Models\OrderProfit;
use Illuminate\Support\Facades\DB;

/**
 * Assembles revenue − COGS − fees into the materialized profit rollups (spec 01 §4.6).
 *
 * Runs in one transaction and is idempotent: re-running it on an unchanged order produces an
 * identical row and inserts no new ledger entries.
 */
class OrderProfitCalculator
{
    public const CALC_VERSION = 1;

    /** Order states where the sale is real enough to recognise COGS against. */
    private const COMMITTED_STATUSES = [
        'paid', 'partially_paid', 'shipped', 'fulfilled', 'delivered', 'completed', 'processing',
    ];

    public function __construct(
        private readonly CostResolver $costs,
        private readonly FifoLedger $ledger,
        private readonly VatCalculator $vat,
    ) {
    }

    public function calculate(Order $order): ?OrderProfit
    {
        $store = $order->store;

        if (! $store) {
            return null;
        }

        return DB::transaction(function () use ($order, $store) {
            $organizationId = (int) $store->organization_id;
            $placedOn = ($order->placed_at ?? $order->created_at)->toDateString();
            $items = $order->items()->get();

            // Recognise COGS first so the ledger reflects this order before we read from it.
            if ($this->isCommitted($order)) {
                foreach ($items as $item) {
                    $this->ledger->consume($item);
                }
            }

            $lines = $this->buildLines($order, $items, $organizationId);
            $orderLevelFees = $this->orderLevelFees($order);
            $netRevenueTotal = Money::sum(...array_column($lines, 'net_revenue'));

            $this->allocateOrderFees($lines, $orderLevelFees, $netRevenueTotal, $items);

            return $this->persist($order, $store, $organizationId, $placedOn, $lines);
        });
    }

    private function isCommitted(Order $order): bool
    {
        $status = strtolower((string) ($order->financial_status ?? $order->status));

        return in_array($status, self::COMMITTED_STATUSES, true);
    }

    /** Per-line revenue, VAT, COGS and directly-booked fees. */
    private function buildLines(Order $order, $items, int $organizationId): array
    {
        $rate = $this->vat->rateFor(null, $order);
        $inclusive = $this->vat->isInclusive(null, $order);
        $lines = [];

        foreach ($items as $item) {
            $gross = Money::multiply($item->price, (int) $item->quantity);
            $afterDiscount = Money::subtract($gross, $item->discount_total ?? 0);
            [$net, $vatAmount] = $this->vat->split($afterDiscount, $rate, $inclusive);

            $cogs = $this->cogsFor($item, $order, $organizationId);

            $directFees = (string) OrderFee::query()
                ->where('order_item_id', $item->id)
                ->costBearing()
                ->sum('amount_base');

            $lines[] = [
                'item' => $item,
                'gross' => $gross,
                'net_revenue' => $net,
                'vat' => $vatAmount,
                'cogs' => $cogs['amount'],
                'cogs_missing' => $cogs['missing'],
                'cogs_estimated' => $cogs['estimated'],
                'direct_fees' => Money::sum($directFees),
                'allocated_fees' => '0.0000',
            ];
        }

        return $lines;
    }

    /**
     * COGS for a line: the FIFO ledger is authoritative when it has entries, otherwise fall back
     * to the resolved cost definition. A line with no resolvable cost contributes zero and is
     * flagged — never a guessed number.
     */
    private function cogsFor(OrderItem $item, Order $order, int $organizationId): array
    {
        // Gross sale COGS only. Refund reversals (restock/writeoff) live in the same table but are
        // reported separately as refund_cogs / lost_cogs — folding them in here would net them out of
        // cogs_base AND credit them again in net_profit, double-counting the recovery.
        $ledgerTotal = (string) CostLayerConsumption::query()
            ->where('order_item_id', $item->id)
            ->whereNotIn('reason', [
                CostLayerConsumption::REASON_REFUND_RESTOCK,
                CostLayerConsumption::REASON_REFUND_WRITEOFF,
            ])
            ->sum('amount_base');

        if (! Money::isZero($ledgerTotal)) {
            return ['amount' => Money::sum($ledgerTotal), 'missing' => false, 'estimated' => false];
        }

        // Nothing in the ledger: either not committed yet, or no layers matched.
        $resolved = $this->costs->resolve(
            $organizationId,
            $item->sku,
            $order->store_id,
            $order->placed_at ?? $order->created_at
        );

        if ($resolved->isMissing) {
            return ['amount' => '0.0000', 'missing' => true, 'estimated' => true];
        }

        return [
            'amount' => $resolved->totalFor((int) $item->quantity),
            'missing' => false,
            'estimated' => true,
        ];
    }

    private function orderLevelFees(Order $order): string
    {
        return Money::sum((string) OrderFee::query()
            ->where('order_id', $order->id)
            ->whereNull('order_item_id')
            ->costBearing()
            ->sum('amount_base'));
    }

    /**
     * Spread order-level fees across lines by share of net revenue.
     *
     * When every line is fully discounted (net revenue 0), revenue weighting would divide by
     * zero — fall back to quantity share so the fees still land somewhere.
     */
    private function allocateOrderFees(array &$lines, string $orderLevelFees, string $netRevenueTotal, $items): void
    {
        if (Money::isZero($orderLevelFees) || empty($lines)) {
            return;
        }

        $useRevenue = ! Money::isZero($netRevenueTotal);
        $totalQty = max(1, (int) $items->sum('quantity'));
        $allocated = 0;
        $feeMinor = Money::toMinor($orderLevelFees);
        $lastIndex = count($lines) - 1;

        foreach ($lines as $i => &$line) {
            if ($i === $lastIndex) {
                // Give the remainder to the final line so the parts always sum to the whole —
                // otherwise rounding leaves a stray fraction unattributed.
                $line['allocated_fees'] = Money::fromMinor($feeMinor - $allocated);
                break;
            }

            $weight = $useRevenue
                ? (Money::ratio($line['net_revenue'], $netRevenueTotal) ?? 0.0)
                : ((int) $line['item']->quantity / $totalQty);

            $share = (int) round($feeMinor * $weight);
            $allocated += $share;
            $line['allocated_fees'] = Money::fromMinor($share);
        }
    }

    private function persist(Order $order, $store, int $organizationId, string $placedOn, array $lines): OrderProfit
    {
        $grossRevenue = Money::sum(...array_column($lines, 'gross'));
        $netRevenue = Money::sum(...array_column($lines, 'net_revenue'));
        $vatTotal = Money::sum(...array_column($lines, 'vat'));
        $cogsTotal = Money::sum(...array_column($lines, 'cogs'));

        $feesTotal = Money::sum((string) OrderFee::query()
            ->where('order_id', $order->id)
            ->costBearing()
            ->sum('amount_base'));

        $feesByType = OrderFee::query()
            ->where('order_id', $order->id)
            ->costBearing()
            ->selectRaw('type, SUM(amount_base) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($v) => (float) $v)
            ->all();

        // Reversals are stored negative; refund_cogs is COGS we got back (restocked), lost_cogs
        // is COGS we did not (written off).
        $refundCogs = $this->absSumOfReversals($order, CostLayerConsumption::REASON_REFUND_RESTOCK);
        $lostCogs = $this->absSumOfReversals($order, CostLayerConsumption::REASON_REFUND_WRITEOFF);

        // Revenue given back to the customer via a succeeded refund (Returns, spec 03). The item
        // portion only, and de-VAT'd to match net_revenue: the VAT was never profit (the merchant
        // reclaims it), so only the ex-VAT amount should reverse out of the P&L.
        $refundGross = (float) \App\Models\Refund::where('order_id', $order->id)->where('status', 'succeeded')->sum('items_amount');
        $org = $store->organization;
        $refundNet = ($org?->prices_include_vat ?? true)
            ? $refundGross / (1 + (float) ($org?->default_vat_rate ?? 0.15))
            : $refundGross;
        $refundRevenue = Money::sum($refundNet);

        // refund_cogs is ADDED back (a restocked return we no longer bear the cost of); refund
        // revenue is SUBTRACTED (money returned to the buyer). A fully restocked+refunded order
        // nets out to roughly −fees, which is the real loss: the merchant ate the platform fee.
        $netProfit = Money::sum(
            $netRevenue,
            '-'.ltrim($cogsTotal, '-'),
            '-'.ltrim($feesTotal, '-'),
            $refundCogs,
            '-'.ltrim($refundRevenue, '-')
        );

        $missingCost = (bool) array_sum(array_map(fn ($l) => $l['cogs_missing'] ? 1 : 0, $lines));
        $estimated = (bool) array_sum(array_map(fn ($l) => $l['cogs_estimated'] ? 1 : 0, $lines));

        $profit = OrderProfit::updateOrCreate(
            ['order_id' => $order->id],
            [
                'organization_id' => $organizationId,
                'store_id' => $store->id,
                'placed_on' => $placedOn,
                'base_currency' => $store->organization?->base_currency ?? 'SAR',
                'gross_revenue_base' => $grossRevenue,
                'discounts_base' => '0.0000',
                'shipping_revenue_base' => '0.0000',
                'net_revenue_base' => $netRevenue,
                'vat_base' => $vatTotal,
                'cogs_base' => $cogsTotal,
                'total_fees_base' => $feesTotal,
                'fees_by_type' => $feesByType,
                'ad_spend_base' => '0.0000',
                'refund_revenue_base' => $refundRevenue,
                'refund_cogs_base' => $refundCogs,
                'lost_cogs_base' => $lostCogs,
                'net_profit_base' => $netProfit,
                'margin_pct' => Money::ratio($netProfit, $netRevenue),
                'is_estimated' => $estimated,
                'estimated_share' => 0,
                'missing_cost' => $missingCost,
                'calc_version' => self::CALC_VERSION,
                'computed_at' => now(),
            ]
        );

        foreach ($lines as $line) {
            $item = $line['item'];
            $lineProfit = Money::sum(
                $line['net_revenue'],
                '-'.ltrim($line['cogs'], '-'),
                '-'.ltrim($line['direct_fees'], '-'),
                '-'.ltrim($line['allocated_fees'], '-')
            );

            OrderItemProfit::updateOrCreate(
                ['order_item_id' => $item->id],
                [
                    'organization_id' => $organizationId,
                    'order_id' => $order->id,
                    'store_id' => $store->id,
                    'sku' => $item->sku,
                    'placed_on' => $placedOn,
                    'quantity' => (int) $item->quantity,
                    'net_revenue_base' => $line['net_revenue'],
                    'vat_base' => $line['vat'],
                    'cogs_base' => $line['cogs'],
                    'direct_fees_base' => $line['direct_fees'],
                    'allocated_fees_base' => $line['allocated_fees'],
                    'ad_spend_base' => '0.0000',
                    'net_profit_base' => $lineProfit,
                    'margin_pct' => Money::ratio($lineProfit, $line['net_revenue']),
                    'is_estimated' => $line['cogs_estimated'],
                ]
            );
        }

        return $profit;
    }

    private function absSumOfReversals(Order $order, string $reason): string
    {
        $sum = (string) CostLayerConsumption::query()
            ->where('order_id', $order->id)
            ->where('reason', $reason)
            ->sum('amount_base');

        return Money::fromMinor(abs(Money::toMinor($sum)));
    }
}
