<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Profit\FeeEstimator;
use App\Services\Profit\OrderProfitCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Materialises the profit rollup for a single order (spec 01).
 *
 * Dispatched from SyncOrdersJob after an order is upserted, so the P&L screen reflects orders as
 * they arrive rather than on a nightly batch. First applies rule-based fee estimates (a no-op where
 * settled fees already exist), then runs the calculator — which recognises COGS through the FIFO
 * ledger and writes order_profits / order_item_profits.
 *
 * Idempotent: the fee estimator and the FIFO ledger both key their writes deterministically, so
 * re-running for the same order (a re-sync) updates rather than double-counts.
 */
class CalculateOrderProfitJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId)
    {
    }

    public function handle(FeeEstimator $feeEstimator, OrderProfitCalculator $calculator): void
    {
        $order = Order::with('store')->find($this->orderId);

        if (! $order) {
            return;
        }

        try {
            // Fill in modelled fees for platforms that never report them, then compute profit.
            $feeEstimator->estimate($order);
            $calculator->calculate($order);
        } catch (\Throwable $e) {
            // Profit is derived data; a failure here must not fail the order sync that queued it.
            Log::error("CalculateOrderProfitJob failed for order {$this->orderId}: " . $e->getMessage());
        }
    }
}
