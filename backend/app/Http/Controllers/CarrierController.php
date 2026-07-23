<?php

namespace App\Http\Controllers;

use App\Services\Shipping\CarrierFactory;

/**
 * The static carrier catalogue (spec 04 §5.7) — what the merchant can connect and what each carrier
 * can do — so the account-creation UI renders without a round-trip. Only carriers the factory can
 * actually build are marked `available`; the rest are shown as "coming soon".
 */
class CarrierController extends Controller
{
    public function catalog()
    {
        $available = CarrierFactory::available();
        $carriers = [];

        foreach (config('shipping.carriers', []) as $code => $meta) {
            $carriers[] = [
                'code' => $code,
                'label' => $meta['label'] ?? ucfirst($code),
                'available' => in_array($code, $available, true),
                'capabilities' => $meta['capabilities'] ?? [],
                'credential_fields' => $meta['credentials'] ?? [],
            ];
        }

        return response()->json(['carriers' => $carriers]);
    }
}
