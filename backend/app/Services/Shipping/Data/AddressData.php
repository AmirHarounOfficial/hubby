<?php

namespace App\Services\Shipping\Data;

use App\Models\OrderAddress;

/** An immutable address passed to a carrier (spec 04 §5.1). No package dependency. */
final class AddressData
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $company = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $line1 = null,
        public readonly ?string $line2 = null,
        public readonly ?string $district = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $postalCode = null,
        public readonly string $countryCode = 'SA',
        public readonly ?string $shortAddress = null,
    ) {
    }

    public static function fromModel(OrderAddress $a): self
    {
        return new self(
            name: $a->name,
            company: $a->company,
            phone: $a->phone,
            email: $a->email,
            line1: $a->line1,
            line2: $a->line2,
            district: $a->district,
            city: $a->city,
            state: $a->state,
            postalCode: $a->postal_code,
            countryCode: $a->country_code ?? 'SA',
            shortAddress: $a->short_address,
        );
    }
}
