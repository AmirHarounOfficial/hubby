<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\OrderAddress;

/**
 * Persist the ship-to address from a synced platform order (spec 04 §4.8 dependency). Nothing
 * captured addresses before — they lived only in orders.raw_data — so shipping had nothing to label.
 * This maps each platform's shape to our structured OrderAddress, normalizing it (phone → E.164,
 * Arabic digits → ASCII, city → canonical) on the way in so the address is carrier-ready.
 */
class AddressExtractor
{
    public function __construct(private readonly AddressValidator $validator)
    {
    }

    public function forOrder(Order $order, string $platform, array $raw): ?OrderAddress
    {
        $mapped = $this->map($platform, $raw);
        if (! $mapped || (empty($mapped['city']) && empty($mapped['line1']))) {
            return null; // nothing usable to persist
        }

        $result = $this->validator->validate($mapped, (int) ($order->store?->organization_id ?? 0));
        $n = $result['normalized'];

        return OrderAddress::updateOrCreate(
            ['order_id' => $order->id, 'type' => 'ship_to'],
            [
                'organization_id' => $order->store?->organization_id,
                'name' => $n['name'] ?? null,
                'phone' => $n['phone'] ?? null,
                'phone_alt' => $n['phone_alt'] ?? null,
                'email' => $n['email'] ?? null,
                'line1' => $n['line1'] ?? null,
                'line2' => $n['line2'] ?? null,
                'district' => $n['district'] ?? null,
                'city' => $n['city'] ?? null,
                'city_normalized' => $n['city_normalized'] ?? null,
                'state' => $n['state'] ?? null,
                'postal_code' => $n['postal_code'] ?? null,
                'country_code' => $n['country_code'] ?? 'SA',
                'is_validated' => $n['is_validated'] ?? false,
                'validation_source' => 'internal',
                'validation_notes' => $result['notes'],
                'raw' => $mapped,
            ]
        );
    }

    /** Map a platform's raw order payload to our generic address shape. */
    private function map(string $platform, array $raw): ?array
    {
        return match ($platform) {
            'shopify' => $this->shopify($raw),
            'salla' => $this->salla($raw),
            'trendyol' => $this->trendyol($raw),
            default => null,
        };
    }

    private function shopify(array $raw): ?array
    {
        $a = $raw['shipping_address'] ?? $raw['billing_address'] ?? null;
        if (! $a) {
            return null;
        }

        return [
            'name' => $a['name'] ?? trim(($a['first_name'] ?? '').' '.($a['last_name'] ?? '')),
            'phone' => $a['phone'] ?? ($raw['phone'] ?? null),
            'line1' => $a['address1'] ?? null,
            'line2' => $a['address2'] ?? null,
            'city' => $a['city'] ?? null,
            'state' => $a['province'] ?? null,
            'postal_code' => $a['zip'] ?? null,
            'country_code' => $a['country_code'] ?? 'SA',
        ];
    }

    private function salla(array $raw): ?array
    {
        $a = $raw['ship_to'] ?? $raw['shipping']['address'] ?? $raw['shipping_address'] ?? null;
        if (! $a) {
            return null;
        }

        return [
            'name' => $a['name'] ?? ($raw['customer']['name'] ?? null),
            'phone' => $a['phone'] ?? ($raw['customer']['mobile'] ?? null),
            'line1' => $a['street'] ?? $a['address_line'] ?? $a['line1'] ?? null,
            'district' => $a['block'] ?? $a['district'] ?? null,
            'city' => $a['city'] ?? null,
            'postal_code' => $a['postal_code'] ?? null,
            'country_code' => $a['country_code'] ?? ($a['country'] ?? 'SA'),
        ];
    }

    private function trendyol(array $raw): ?array
    {
        $a = $raw['shipmentAddress'] ?? $raw['invoiceAddress'] ?? null;
        if (! $a) {
            return null;
        }

        return [
            'name' => $a['fullName'] ?? trim(($a['firstName'] ?? '').' '.($a['lastName'] ?? '')),
            'phone' => $a['phone'] ?? null,
            'line1' => $a['address1'] ?? $a['fullAddress'] ?? null,
            'line2' => $a['address2'] ?? null,
            'district' => $a['district'] ?? null,
            'city' => $a['city'] ?? null,
            'postal_code' => $a['postalCode'] ?? null,
            'country_code' => $a['countryCode'] ?? 'TR',
        ];
    }
}
