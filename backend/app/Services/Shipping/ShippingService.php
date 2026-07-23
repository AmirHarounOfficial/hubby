<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentPackage;
use App\Services\Shipping\LabelStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates the shipment lifecycle (spec 04 §5.2): createDraft → purchaseLabel → cancel. Owns the
 * transactions and the merchant-triggered state transitions; carrier-driven states arrive through
 * TrackingIngestService, never here.
 */
class ShippingService
{
    public function __construct(private readonly LabelStorageService $labels)
    {
    }

    /**
     * Create a `draft` shipment for an order. Packages default to a single box carrying every order
     * line at the resolved weight; the merchant refines them before buying a label.
     *
     * @param array<string, mixed> $attrs
     */
    public function createDraft(Order $order, array $attrs = []): Shipment
    {
        $store = $order->store;

        return DB::transaction(function () use ($order, $store, $attrs) {
            $shipment = Shipment::create(array_merge([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'order_id' => $order->id,
                'direction' => 'outbound',
                'reference' => $this->nextReference((int) $store->organization_id),
                'status' => Shipment::STATUS_DRAFT,
                'currency' => $order->currency ?? 'SAR',
                'is_cod' => (bool) ($order->is_cod ?? false),
                'cod_amount' => $order->is_cod ? (float) $order->cod_amount : 0,
                'cod_currency' => $order->is_cod ? ($order->currency ?? 'SAR') : null,
                'public_tracking_slug' => Str::lower(Str::random(20)),
                'created_by_user_id' => $attrs['created_by_user_id'] ?? null,
            ], array_diff_key($attrs, ['created_by_user_id' => true])));

            $package = ShipmentPackage::create([
                'shipment_id' => $shipment->id,
                'sequence' => 1,
                'weight_kg' => (float) ($attrs['weight_kg'] ?? 0),
            ]);

            foreach ($order->items as $item) {
                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'shipment_package_id' => $package->id,
                    'order_item_id' => $item->id,
                    'sku' => $item->sku,
                    'name' => $item->name,
                    'quantity' => (int) $item->quantity,
                    'unit_value' => (float) $item->price,
                ]);
            }

            return $shipment->fresh(['packages', 'items']);
        });
    }

    /**
     * Buy the label / AWB from the carrier and move the shipment to `label_purchased`. Slice 1 wires
     * the `manual` carrier (no external call); real carriers slot in behind the same interface.
     */
    public function purchaseLabel(Shipment $shipment, CarrierAccount $account, array $opts = []): Shipment
    {
        // Idempotency: a shipment that already has a purchased AWB is returned as-is. Double-clicking
        // "Buy label" must never buy two labels — every carrier bills per AWB (spec §4.4 step 2).
        if ($shipment->status === Shipment::STATUS_LABEL_PURCHASED && $shipment->tracking_number) {
            return $shipment->fresh(['packages', 'items']);
        }

        if (! in_array($shipment->status, [Shipment::STATUS_DRAFT, Shipment::STATUS_RATED], true)) {
            ShipmentStateMachine::assert($shipment->status, Shipment::STATUS_LABEL_PURCHASED);
        }
        if ($shipment->packages->isEmpty() || $shipment->packages->every(fn ($p) => (float) $p->weight_kg <= 0)) {
            throw new \RuntimeException('shipment_requires_a_package_with_weight');
        }
        if ($shipment->is_cod && (float) $shipment->cod_amount <= 0) {
            throw new \RuntimeException('cod_shipment_requires_a_cod_amount');
        }

        $carrier = CarrierFactory::make($account->carrier_code);

        return DB::transaction(function () use ($shipment, $account, $carrier) {
            $result = $carrier->createShipment($account, $shipment);

            $shipment->forceFill([
                'carrier_account_id' => $account->id,
                'carrier_code' => $account->carrier_code,
                'tracking_number' => $result['tracking_number'] ?: $shipment->tracking_number,
                'carrier_shipment_id' => $result['carrier_shipment_id'] ?? null,
                'status' => Shipment::STATUS_LABEL_PURCHASED,
                'shipping_cost' => $result['cost']['amount'] ?? $shipment->shipping_cost,
                'shipping_cost_currency' => $result['cost']['currency'] ?? $shipment->shipping_cost_currency,
                'estimated_delivery_at' => $result['estimated_delivery_at'] ?? null,
                'raw_response' => $result['raw'] ?? null,
            ])->save();

            foreach ($result['packages'] ?? [] as $pkg) {
                if (! empty($pkg['tracking_number'])) {
                    $shipment->packages()->where('sequence', $pkg['sequence'])
                        ->update(['tracking_number' => $pkg['tracking_number']]);
                }
            }

            // Download and keep our own copy of the label the moment it exists — carrier URLs expire.
            if (! empty($result['label'])) {
                $this->labels->store($shipment, $result['label']);
            }

            return $shipment->fresh(['packages', 'items', 'labels']);
        });
    }

    /** Cancel a pre-transit shipment (void the label with the carrier where supported). */
    public function cancel(Shipment $shipment): Shipment
    {
        ShipmentStateMachine::assert($shipment->status, Shipment::STATUS_CANCELLED);

        return DB::transaction(function () use ($shipment) {
            if ($shipment->carrier_account_id && $shipment->carrier_code) {
                $carrier = CarrierFactory::make($shipment->carrier_code);
                if ($carrier->supports('cancel')) {
                    try {
                        $carrier->cancelShipment($shipment->carrierAccount, $shipment);
                    } catch (\Throwable $e) {
                        // best-effort void; the local cancel still stands
                    }
                }
            }

            $shipment->forceFill(['status' => Shipment::STATUS_CANCELLED, 'cancelled_at' => now()])->save();

            return $shipment;
        });
    }

    /** SHP-YYYY-NNNNNN, sequential per org. */
    private function nextReference(int $organizationId): string
    {
        $seq = Shipment::where('organization_id', $organizationId)->count() + 1;

        return 'SHP-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
