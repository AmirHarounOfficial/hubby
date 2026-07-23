<?php

namespace App\Services\Returns;

use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use Illuminate\Support\Facades\DB;

/**
 * Returns analytics (spec 03 §8): the numbers that turn returns from a cost centre into a
 * merchandising signal — return rate, the MENA-specific RTO rate, how much actually gets back on the
 * shelf (restock ratio), refund value, and the top reasons and channels.
 */
class ReturnsReportService
{
    public function summary(int $organizationId, string $from, string $to): array
    {
        $base = ReturnRequest::where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to.' 23:59:59']);

        $total = (clone $base)->count();
        $rto = (clone $base)->where('type', 'rto')->count();

        // Orders placed in the window — the denominator for return rate.
        $orders = Order::whereHas('store', fn ($q) => $q->where('organization_id', $organizationId))
            ->whereRaw('COALESCE(placed_at, created_at) BETWEEN ? AND ?', [$from, $to.' 23:59:59'])
            ->count();

        $inWindow = fn ($q) => $q->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to.' 23:59:59']);
        $received = (int) ReturnItem::whereHas('returnRequest', $inWindow)->sum('quantity_received');
        $restocked = (int) ReturnItem::whereHas('returnRequest', $inWindow)->sum('quantity_restocked');

        $refundValue = (float) Refund::where('organization_id', $organizationId)
            ->where('status', 'succeeded')
            ->whereBetween('processed_at', [$from, $to.' 23:59:59'])
            ->sum('amount');

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_returns' => $total,
            'rto_returns' => $rto,
            'orders' => $orders,
            'return_rate' => $orders > 0 ? round($total / $orders, 4) : null,
            'rto_rate' => $total > 0 ? round($rto / $total, 4) : null,
            'restock_ratio' => $received > 0 ? round($restocked / $received, 4) : null,
            'refund_value' => number_format($refundValue, 2, '.', ''),
            'by_status' => (clone $base)->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')->pluck('count', 'status'),
            'by_reason' => (clone $base)->whereNotNull('reason_code')
                ->select('reason_code', DB::raw('COUNT(*) as count'))
                ->groupBy('reason_code')->orderByDesc('count')->limit(10)
                ->pluck('count', 'reason_code'),
        ];
    }
}
