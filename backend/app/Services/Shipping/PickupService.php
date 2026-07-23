<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\PickupRequest;

/**
 * Pickup requests (spec 04 §4.10) — "send a driver". Books with the carrier where supported; a
 * carrier on a scheduled daily pickup simply records a local request (carrier_pickup_id null).
 */
class PickupService
{
    /**
     * @param array<string,mixed> $data
     */
    public function create(int $organizationId, CarrierAccount $account, array $data, ?int $userId = null): PickupRequest
    {
        $pickup = PickupRequest::create([
            'organization_id' => $organizationId,
            'carrier_account_id' => $account->id,
            'carrier_code' => $account->carrier_code,
            'reference' => $this->nextReference($organizationId),
            'status' => 'requested',
            'pickup_address_id' => $data['pickup_address_id'] ?? null,
            'pickup_date' => $data['pickup_date'],
            'ready_at' => $data['ready_at'] ?? null,
            'close_at' => $data['close_at'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'pieces' => $data['pieces'] ?? 1,
            'total_weight_kg' => $data['total_weight_kg'] ?? 0,
            'instructions' => $data['instructions'] ?? null,
            'created_by_user_id' => $userId,
        ]);

        $result = CarrierFactory::make($account->carrier_code)->createPickup($account, $pickup);

        $pickup->forceFill([
            'carrier_pickup_id' => $result['carrier_pickup_id'] ?? null,
            'status' => ($result['confirmed'] ?? false) ? 'confirmed' : 'requested',
            'raw_response' => $result['raw'] ?? null,
        ])->save();

        return $pickup->fresh();
    }

    public function cancel(PickupRequest $pickup): PickupRequest
    {
        try {
            CarrierFactory::make($pickup->carrier_code)->cancelPickup($pickup->carrierAccount, $pickup);
        } catch (\Throwable $e) {
            // best-effort; the local cancel still stands
        }
        $pickup->update(['status' => 'cancelled']);

        return $pickup;
    }

    /** PKP-YYYY-NNNNNN, sequential per org. */
    private function nextReference(int $organizationId): string
    {
        $seq = PickupRequest::where('organization_id', $organizationId)->count() + 1;

        return 'PKP-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
