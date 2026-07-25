<?php

namespace App\Services\Cod;

use App\Models\CodTransaction;
use Illuminate\Support\Facades\DB;

/**
 * The cash-flow view of COD (spec 06 §4.5): how much cash carriers are about to collect, how much
 * they've collected and still owe (with aging), how much is overdue, and how much RTO ate. This is
 * the number a MENA merchant cannot get anywhere else.
 */
class CodReconciliationService
{
    public function summary(int $organizationId): array
    {
        $base = CodTransaction::where('organization_id', $organizationId);

        $collected = (clone $base)->where('status', 'collected');
        $collectedAmt = fn ($q) => (float) $q->sum(DB::raw('COALESCE(collected_amount, expected_amount)'));

        $aging = ['0-7' => 0.0, '8-14' => 0.0, '15-30' => 0.0, '30+' => 0.0];
        foreach ((clone $base)->where('status', 'collected')->get() as $txn) {
            if ($bucket = $txn->aging_bucket) {
                $aging[$bucket] += (float) ($txn->collected_amount ?? $txn->expected_amount);
            }
        }

        return [
            'currency' => (clone $base)->value('currency') ?? 'SAR',
            // Carrier will collect this cash (dispatched, not yet delivered).
            'in_transit' => (float) (clone $base)->where('status', 'in_transit')->sum('expected_amount'),
            // Carrier has this cash and owes it to the merchant.
            'awaiting_remittance' => $collectedAmt(clone $collected),
            // Owed past the remittance cycle — chase the carrier.
            'overdue' => $collectedAmt((clone $collected)->where('due_at', '<', now())),
            // Actually paid to the merchant in the last 30 days.
            'remitted_30d' => (float) (clone $base)->where('status', 'remitted')->where('remitted_at', '>=', now()->subDays(30))->sum('remitted_amount'),
            // COD lost to RTO (goods came back, no cash).
            'rto_amount' => (float) (clone $base)->whereIn('status', ['rto', 'rto_closed'])->sum('expected_amount'),
            'rto_count' => (clone $base)->whereIn('status', ['rto', 'rto_closed'])->count(),
            'aging' => $aging,
            'by_status' => (clone $base)->select('status', DB::raw('COUNT(*) as count'))
                ->groupBy('status')->pluck('count', 'status'),
        ];
    }
}
