<?php

namespace App\Services\Returns;

use App\Models\ReturnRequest;

/**
 * Deterministic refund math (spec 03 §4.4) — always re-derived, never trusted from the client.
 *
 * Per approved line: gross = unit_price × refundable_qty, then discount/tax allocated pro-rata
 * (both 0 for legacy lines synced before per-line tax existed). Header total nets shipping refund,
 * restocking fee and any customer-paid return leg. refundable_qty uses received quantity once the
 * parcel is in, else approved quantity to preview at approval time.
 */
class RefundCalculator
{
    /**
     * @return array{items_subtotal: float, tax_refund: float, total_refund: float, lines: array<int, float>}
     */
    public function compute(ReturnRequest $rma): array
    {
        $received = in_array($rma->status, ['received', 'inspecting', 'inspected', 'refund_pending', 'refunded'], true);

        $itemsSubtotal = 0.0;
        $taxRefund = 0.0;
        $lines = [];

        foreach ($rma->items as $item) {
            $qty = $received ? (int) $item->quantity_received : (int) $item->quantity_approved;
            if ($qty <= 0) {
                $lines[$item->id] = 0.0;
                continue;
            }

            $orderedQty = max(1, (int) ($item->orderItem->quantity ?? $qty));
            $ratio = $qty / $orderedQty;

            $gross = (float) $item->unit_price * $qty;
            $discount = (float) $item->discount_amount * $ratio;
            $tax = (float) $item->tax_amount * $ratio;

            $refund = round($gross - $discount + $tax, 2);
            $lines[$item->id] = $refund;
            $itemsSubtotal += round($gross - $discount, 2); // subtotal excludes tax
            $taxRefund += round($tax, 2);
        }

        $customerPaidReturn = $rma->return_shipping_paid_by === 'customer' ? (float) $rma->return_shipping_cost : 0.0;
        $total = $itemsSubtotal + $taxRefund + (float) $rma->shipping_refund
            - (float) $rma->restocking_fee - $customerPaidReturn;

        return [
            'items_subtotal' => round($itemsSubtotal, 2),
            'tax_refund' => round($taxRefund, 2),
            'total_refund' => round(max(0, $total), 2),
            'lines' => $lines,
        ];
    }
}
