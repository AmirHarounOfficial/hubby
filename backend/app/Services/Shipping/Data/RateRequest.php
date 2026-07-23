<?php

namespace App\Services\Shipping\Data;

/** An immutable rate-shopping request (spec 04 §4.3). */
final class RateRequest
{
    /**
     * @param array<int, PackageData> $packages
     */
    public function __construct(
        public readonly AddressData $from,
        public readonly AddressData $to,
        public readonly array $packages,
        public readonly float $declaredValue = 0.0,
        public readonly string $currency = 'SAR',
        public readonly bool $isCod = false,
        public readonly float $codAmount = 0.0,
        public readonly ?string $serviceFilter = null,
    ) {
    }

    public function totalWeightKg(): float
    {
        return round(array_sum(array_map(fn (PackageData $p) => $p->weightKg, $this->packages)), 3);
    }

    /** Deterministic cache key over everything that changes a quote (spec §4.3 step 1). */
    public function requestHash(): string
    {
        $packages = array_map(fn (PackageData $p) => [
            $p->weightKg, $p->lengthCm, $p->widthCm, $p->heightCm,
        ], $this->packages);

        return sha1(json_encode([
            $this->from->city, $this->from->countryCode,
            $this->to->city, $this->to->district, $this->to->countryCode,
            $packages, $this->declaredValue, $this->isCod, $this->codAmount, $this->serviceFilter,
        ]));
    }
}
