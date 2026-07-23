<?php

namespace App\Services\Shipping\Data;

/** An immutable parcel (weight in kg, dimensions in cm) passed to a carrier (spec 04 §5.1). */
final class PackageData
{
    public function __construct(
        public readonly float $weightKg,
        public readonly ?float $lengthCm = null,
        public readonly ?float $widthCm = null,
        public readonly ?float $heightCm = null,
        public readonly float $declaredValue = 0.0,
        public readonly string $packageType = 'box',
        public readonly ?string $contentsDescription = null,
    ) {
    }

    /** Volumetric weight (kg) using the standard 5000 divisor; null if any dimension is missing. */
    public function volumetricWeightKg(int $divisor = 5000): ?float
    {
        if (! $this->lengthCm || ! $this->widthCm || ! $this->heightCm) {
            return null;
        }

        return round(($this->lengthCm * $this->widthCm * $this->heightCm) / $divisor, 3);
    }

    /** max(actual, volumetric) — what carriers actually bill on. */
    public function chargeableWeightKg(int $divisor = 5000): float
    {
        return max($this->weightKg, $this->volumetricWeightKg($divisor) ?? 0.0);
    }
}
