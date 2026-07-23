<?php

namespace App\Services\Profit\FeeCapture;

use App\Models\OrderFee;

/**
 * Turns an SP-API Finances `listFinancialEventsByOrderId` response into normalised OrderFee rows.
 *
 * Amazon reports fees as amounts leaving the seller's account, i.e. NEGATIVE CurrencyAmounts. We
 * flip the sign so a charge becomes a positive cost (OrderFee's convention), and map Amazon's fee
 * vocabulary onto our fee types. Pure and side-effect free so it can be tested against captured
 * sample payloads without touching the network.
 */
class AmazonFeeParser
{
    /**
     * @param  array  $financialEvents  the `payload.FinancialEvents` object from the API.
     * @return array<int, array<string, mixed>>
     */
    public static function parse(array $financialEvents): array
    {
        $fees = [];

        foreach ($financialEvents['ShipmentEventList'] ?? [] as $shipment) {
            // Order-level shipment fees.
            foreach ($shipment['ShipmentFeeList'] ?? [] as $i => $fee) {
                $row = self::mapFee($fee, 'shipfee-'.$i);
                if ($row) {
                    $fees[] = $row;
                }
            }

            // Per-item fees (commission, FBA fulfilment, etc.).
            foreach ($shipment['ShipmentItemList'] ?? [] as $item) {
                $itemId = $item['OrderItemId'] ?? 'item';
                foreach ($item['ItemFeeList'] ?? [] as $i => $fee) {
                    $row = self::mapFee($fee, $itemId.'-'.$i);
                    if ($row) {
                        $fees[] = $row;
                    }
                }
            }
        }

        return $fees;
    }

    /** @return array<string, mixed>|null */
    private static function mapFee(array $fee, string $ref): ?array
    {
        $feeType = $fee['FeeType'] ?? null;
        $amount = $fee['FeeAmount']['CurrencyAmount'] ?? null;

        if ($feeType === null || $amount === null) {
            return null;
        }

        // Flip sign: Amazon's negative "money out" becomes a positive cost.
        $cost = -1 * (float) $amount;

        if ($cost === 0.0) {
            return null;
        }

        return [
            'type' => self::mapType($feeType),
            'subtype' => $feeType,
            'amount' => number_format($cost, 4, '.', ''),
            'currency' => $fee['FeeAmount']['CurrencyCode'] ?? 'SAR',
            'external_id' => $feeType.':'.$ref,
            'posted_at' => null,
            'raw' => $fee,
        ];
    }

    /** Amazon's FeeType strings → our fee taxonomy. */
    private static function mapType(string $amazonType): string
    {
        $t = strtolower($amazonType);

        return match (true) {
            str_contains($t, 'commission'),
            str_contains($t, 'referral')       => OrderFee::TYPE_COMMISSION,
            str_contains($t, 'fba'),
            str_contains($t, 'fulfillment'),
            str_contains($t, 'fulfilment')     => OrderFee::TYPE_FULFILMENT,
            str_contains($t, 'shipping')        => OrderFee::TYPE_SHIPPING,
            str_contains($t, 'storage')         => OrderFee::TYPE_STORAGE,
            str_contains($t, 'advertising')     => OrderFee::TYPE_ADVERTISING,
            default                             => OrderFee::TYPE_OTHER,
        };
    }
}
