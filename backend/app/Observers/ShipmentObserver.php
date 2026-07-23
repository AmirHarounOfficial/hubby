<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentItem;

/**
 * Keeps orders.fulfillment_status + shipments_count in sync with their shipments (spec 04 §4.1).
 * Fires on every shipment save — including tracking-driven status changes — so the order rollup never
 * lags the shipment truth.
 */
class ShipmentObserver
{
    public function saved(Shipment $shipment): void
    {
        $this->rollup($shipment);
    }

    public function deleted(Shipment $shipment): void
    {
        $this->rollup($shipment);
    }

    private function rollup(Shipment $shipment): void
    {
        if (! $shipment->order_id) {
            return;
        }

        $order = Order::with('items')->find($shipment->order_id);
        if (! $order) {
            return;
        }

        $shipments = Shipment::where('order_id', $order->id)->get();
        $active = $shipments->whereNotIn('status', [Shipment::STATUS_DRAFT, Shipment::STATUS_CANCELLED]);

        $status = $this->resolveStatus($order, $active);

        $order->forceFill([
            'shipments_count' => $active->count(),
            'fulfillment_status' => $status,
        ])->saveQuietly();
    }

    /** @param \Illuminate\Support\Collection<int, Shipment> $active */
    private function resolveStatus(Order $order, $active): ?string
    {
        if ($active->contains(fn (Shipment $s) => in_array($s->status, ['returned_to_origin', 'rto_delivered'], true))) {
            return 'rto';
        }

        if ($active->isEmpty()) {
            return 'unfulfilled';
        }

        $orderedQty = (int) $order->items->sum('quantity');
        $shippedQty = (int) ShipmentItem::whereIn('shipment_id', $active->pluck('id'))->sum('quantity');

        // Only call it partial when line-level detail exists and genuinely under-covers the order.
        // A shipment with no shipment_items (the simple single-parcel case) is assumed to cover it.
        if ($shippedQty > 0 && $orderedQty > 0 && $shippedQty < $orderedQty) {
            return 'partial';
        }

        return $active->every(fn (Shipment $s) => $s->status === 'delivered') ? 'delivered' : 'shipped';
    }
}
