<?php

namespace App\Services\Returns;

use App\Models\ReturnEvent;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * Turns a carrier "returned to origin" scan into a first-class RTO return (spec 03 §4.2, dispatched
 * from Spec 04 tracking). RTO on a COD order is a pure loss in MENA, so surfacing it automatically —
 * the moment the carrier says the parcel is coming back — is the whole point of unifying returns and
 * shipping. Idempotent: a shipment/order that already has an RTO return is a no-op.
 */
class RtoDetector
{
    public function fromShipment(Shipment $shipment): ?ReturnRequest
    {
        if (! $shipment->order_id || $shipment->direction !== 'outbound') {
            return null;
        }

        $exists = ReturnRequest::where('order_id', $shipment->order_id)
            ->where('type', 'rto')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->exists();
        if ($exists) {
            return null;
        }

        $order = $shipment->order()->with('items', 'store')->first();
        if (! $order) {
            return null;
        }

        return DB::transaction(function () use ($order, $shipment) {
            $rma = ReturnRequest::create([
                'organization_id' => $shipment->organization_id,
                'store_id' => $shipment->store_id,
                'order_id' => $order->id,
                'rma_number' => $this->nextRmaNumber((int) $shipment->organization_id),
                'type' => 'rto',
                'origin' => 'carrier',
                'status' => 'in_transit', // the parcel is already on its way back
                'refund_responsibility' => 'merchant',
                'currency' => $order->currency ?? 'SAR',
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'carrier_code' => $shipment->carrier_code,
                'tracking_number' => $shipment->tracking_number,
                'outbound_shipment_id' => $shipment->id,
                'requested_at' => now(),
                'shipped_at' => now(),
            ]);

            foreach ($order->items as $item) {
                ReturnItem::create([
                    'return_request_id' => $rma->id,
                    'order_item_id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity_requested' => (int) $item->quantity,
                    'quantity_approved' => (int) $item->quantity,
                    'unit_price' => $item->price,
                ]);
            }

            ReturnEvent::create([
                'return_request_id' => $rma->id,
                'from_status' => null,
                'to_status' => 'in_transit',
                'actor_type' => 'system',
                'note' => 'RTO detected from carrier tracking (shipment '.$shipment->reference.')',
            ]);

            return $rma->fresh('items');
        });
    }

    /** RMA-YYYY-NNNNNN, sequential per org. */
    private function nextRmaNumber(int $organizationId): string
    {
        $seq = ReturnRequest::where('organization_id', $organizationId)->count() + 1;

        return 'RMA-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
