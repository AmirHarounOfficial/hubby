<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Order;
use App\Models\Store;

/**
 * Implemented by the integrations that can report the *actual* fees a platform charged on an
 * order (Amazon SP-API Finances, Shopify Payments), as opposed to the modelled estimates the
 * FeeEstimator produces. The capture pipeline checks `instanceof` and skips services that don't
 * implement it, so no other integration is forced to stub a method it can't fulfil.
 *
 * @return array<int, array{
 *   type: string, subtype: ?string, amount: string, currency: string,
 *   external_id: ?string, posted_at: ?string, raw: array
 * }>  Normalised, signed fees (positive = cost to the merchant).
 */
interface CapturesOrderFees
{
    public function fetchOrderFees(Store $store, Order $order): array;
}
