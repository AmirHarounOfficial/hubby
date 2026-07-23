<?php

namespace App\Services\Profit\FeeCapture;

use App\Models\OrderFee;

/**
 * Extracts the actual Shopify Payments processing fees from an order's transactions
 * (`/orders/{id}/transactions.json`).
 *
 * Successful sale/capture transactions paid through Shopify Payments carry a `fees` array — the
 * processing fee the merchant actually paid. That fee is already a cost (positive), so no sign
 * flip. Non-Shopify-Payments gateways don't report a fee here and are skipped rather than guessed.
 * Pure and side-effect free.
 */
class ShopifyFeeParser
{
    private const CAPTURE_KINDS = ['sale', 'capture'];

    /**
     * @param  array  $transactions  the `transactions` array from the API.
     * @return array<int, array<string, mixed>>
     */
    public static function parse(array $transactions): array
    {
        $fees = [];

        foreach ($transactions as $txn) {
            if (($txn['status'] ?? null) !== 'success') {
                continue;
            }
            if (! in_array($txn['kind'] ?? '', self::CAPTURE_KINDS, true)) {
                continue;
            }

            foreach ($txn['fees'] ?? [] as $i => $fee) {
                $amount = $fee['amount']['amount'] ?? $fee['amount'] ?? null;
                if ($amount === null || (float) $amount === 0.0) {
                    continue;
                }

                $fees[] = [
                    'type' => OrderFee::TYPE_PAYMENT,
                    'subtype' => $fee['type'] ?? 'shopify_payments',
                    'amount' => number_format((float) $amount, 4, '.', ''),
                    'currency' => $fee['amount']['currency_code'] ?? ($txn['currency'] ?? 'SAR'),
                    'external_id' => 'txn:'.($txn['id'] ?? 'x').':fee:'.$i,
                    'posted_at' => $txn['processed_at'] ?? null,
                    'raw' => $fee,
                ];
            }
        }

        return $fees;
    }
}
