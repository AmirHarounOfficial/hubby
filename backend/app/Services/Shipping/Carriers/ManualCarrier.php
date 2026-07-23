<?php

namespace App\Services\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Shipping\BaseShippingCarrier;

/**
 * The no-API carrier (spec 04 §4.1): for merchants who ship on a carrier Hubby doesn't integrate,
 * enter the AWB by hand, and update tracking manually. It needs no credentials and calls nothing
 * external, so it's the baseline that makes the whole shipment lifecycle usable — and testable —
 * before a single real carrier is wired.
 */
class ManualCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['cod'];

    public function code(): string
    {
        return 'manual';
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        return true; // nothing to validate
    }

    /**
     * "Create" a manual shipment: no external call. If the merchant supplied a tracking number on the
     * shipment we keep it; otherwise there simply isn't one yet and they can add it later.
     */
    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        return [
            'tracking_number' => $shipment->tracking_number ?? '',
            'carrier_shipment_id' => null,
            'packages' => $shipment->packages->map(fn ($p) => [
                'sequence' => $p->sequence,
                'tracking_number' => $p->tracking_number,
            ])->all(),
            'label' => null,
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['manual' => true],
        ];
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        // A manual carrier has no label service — the merchant uses the carrier's own AWB.
        $this->unsupported('label');
    }
}
