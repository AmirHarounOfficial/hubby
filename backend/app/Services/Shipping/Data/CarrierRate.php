<?php

namespace App\Services\Shipping\Data;

/** An immutable rate quote returned by a carrier (spec 04 §4.3). */
final class CarrierRate
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $serviceCode,
        public readonly ?string $serviceName,
        public readonly float $amount,
        public readonly string $currency = 'SAR',
        public readonly float $codFee = 0.0,
        public readonly float $fuelSurcharge = 0.0,
        public readonly float $vatAmount = 0.0,
        public readonly ?int $transitDaysMin = null,
        public readonly ?int $transitDaysMax = null,
        public readonly bool $isEstimate = false,
        public readonly array $raw = [],
    ) {
    }

    /** The number the UI compares on. */
    public function totalAmount(): float
    {
        return round($this->amount + $this->codFee + $this->fuelSurcharge + $this->vatAmount, 2);
    }
}
