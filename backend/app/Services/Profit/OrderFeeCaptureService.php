<?php

namespace App\Services\Profit;

use App\Models\Order;
use App\Models\OrderFee;
use App\Models\Store;
use App\Services\Integrations\Contracts\CapturesOrderFees;
use App\Services\Integrations\IntegrationFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Captures the *measured* fees a platform actually charged on an order and persists them as
 * `OrderFee` rows flagged `is_estimated = false`. Once a measured fee of a given type exists, the
 * FeeEstimator leaves that type alone — so importing settlement data supersedes the estimate.
 *
 * Best-effort by design: it runs inside the order-profit pipeline, and a marketplace API hiccup
 * must never fail the sync. Deterministic `fee_key` per (order, type, subtype, external_id) makes
 * re-capture idempotent — a settlement re-pull updates rather than duplicates.
 */
class OrderFeeCaptureService
{
    /**
     * @return int  number of measured fees written/updated (0 if the platform can't report them).
     */
    public function capture(Order $order): int
    {
        $store = $order->store;

        if (! $store || ! $store->organization_id) {
            return 0;
        }

        $service = $this->serviceFor($store);

        if (! $service instanceof CapturesOrderFees) {
            return 0; // platform doesn't expose real fees (Salla, Zid, Noon, Woo, Trendyol)
        }

        try {
            $fees = $service->fetchOrderFees($store, $order);
        } catch (\Throwable $e) {
            Log::warning("Fee capture failed for order {$order->id} ({$store->platform}): ".$e->getMessage());

            return 0;
        }

        return $this->persist($order, $store, $fees);
    }

    /** @param array<int, array<string, mixed>> $fees */
    private function persist(Order $order, Store $store, array $fees): int
    {
        if ($fees === []) {
            return 0;
        }

        $written = 0;

        DB::transaction(function () use ($order, $store, $fees, &$written) {
            foreach ($fees as $fee) {
                if (! in_array($fee['type'] ?? null, OrderFee::TYPES, true)) {
                    continue;
                }

                $amount = (string) $fee['amount'];

                $feeKey = OrderFee::buildFeeKey(
                    (string) $order->external_id,
                    $fee['type'],
                    $fee['subtype'] ?? null,
                    $fee['external_id'] ?? null,
                    $amount,
                    $fee['posted_at'] ?? null,
                );

                OrderFee::updateOrCreate(
                    ['store_id' => $store->id, 'fee_key' => $feeKey],
                    [
                        'organization_id' => $store->organization_id,
                        'order_id' => $order->id,
                        'type' => $fee['type'],
                        'subtype' => $fee['subtype'] ?? null,
                        'amount' => $amount,
                        // Base-currency conversion for foreign-currency settlements is a follow-up;
                        // today we assume the fee is already in the org's base currency (as the
                        // estimator does), so amount_base mirrors amount.
                        'amount_base' => $amount,
                        'currency' => $fee['currency'] ?? $order->currency ?? 'SAR',
                        'fx_rate_to_base' => 1,
                        'is_estimated' => false,
                        'source' => 'settlement',
                        'external_id' => $fee['external_id'] ?? null,
                        'posted_at' => $fee['posted_at'] ?? $order->placed_at ?? $order->created_at,
                        'raw_data' => $fee['raw'] ?? null,
                    ]
                );

                $written++;
            }
        });

        return $written;
    }

    protected function serviceFor(Store $store)
    {
        return IntegrationFactory::make($store->platform);
    }
}
