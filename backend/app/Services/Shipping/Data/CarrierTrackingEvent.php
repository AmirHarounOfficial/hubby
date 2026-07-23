<?php

namespace App\Services\Shipping\Data;

use Illuminate\Support\Carbon;

/**
 * An immutable normalized tracking event from a carrier (spec 04 §5.1). `status` is already mapped to
 * the normalized vocabulary (§4.2) by the carrier before this object is built.
 */
final class CarrierTrackingEvent
{
    public function __construct(
        public readonly string $status,
        public readonly Carbon $eventAt,
        public readonly ?string $rawStatus = null,
        public readonly ?string $rawCode = null,
        public readonly ?string $descriptionEn = null,
        public readonly ?string $descriptionAr = null,
        public readonly ?string $location = null,
        public readonly ?string $city = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $signedBy = null,
        public readonly bool $isException = false,
        public readonly ?int $packageSequence = null,
        public readonly array $payload = [],
    ) {
    }

    /** Stable dedupe key within a shipment (spec §3.7). */
    public function fingerprint(int $shipmentId): string
    {
        return sha1(implode('|', [
            $shipmentId, $this->rawCode ?? $this->status, $this->eventAt->toIso8601String(), $this->location ?? '',
        ]));
    }
}
