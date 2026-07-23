<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\CarrierStatusMap;
use App\Services\Shipping\Data\RateRequest;
use App\Services\Shipping\Exceptions\CarrierCapabilityException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared carrier behaviour (spec 04 §5.1): an HTTP client with sane timeouts, the one-and-only
 * status normalizer (data-driven against carrier_status_map — subclasses rarely override), and a
 * capability guard so every carrier can safely stub what it can't do.
 */
abstract class BaseShippingCarrier implements ShippingCarrierInterface
{
    /** @var array<int, string> capabilities this carrier advertises */
    protected array $capabilities = [];

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    protected function getHttpClient(CarrierAccount $account)
    {
        return Http::connectTimeout(10)->timeout(30)->acceptJson();
    }

    /** Throw for any capability a carrier doesn't implement — keeps stubs one line. */
    protected function unsupported(string $capability): never
    {
        throw CarrierCapabilityException::for($this->code(), $capability);
    }

    /**
     * Map a raw carrier status to the normalized vocabulary (§4.2) via carrier_status_map. Matches on
     * code first, then lowercased text; lower `priority` wins. Anything unmapped falls back to
     * `exception` and is logged so new carrier codes get discovered rather than silently mis-rendered.
     */
    public function normalizeStatus(?string $rawStatus, ?string $rawCode = null): string
    {
        $query = CarrierStatusMap::where('carrier_code', $this->code())
            ->where(function ($q) use ($rawStatus, $rawCode) {
                if ($rawCode !== null && $rawCode !== '') {
                    $q->orWhere('raw_code', $rawCode);
                }
                if ($rawStatus !== null && $rawStatus !== '') {
                    $q->orWhereRaw('LOWER(raw_status) = ?', [mb_strtolower($rawStatus)]);
                }
            })
            ->orderBy('priority')
            ->first();

        if ($query) {
            return $query->normalized_status;
        }

        Log::warning("Unmapped carrier status [{$this->code()}]", ['raw_status' => $rawStatus, 'raw_code' => $rawCode]);

        return 'exception';
    }

    /** Is this normalized status a terminal one (per the seeded map, else the well-known set)? */
    public function isFinalStatus(string $normalized): bool
    {
        return in_array($normalized, \App\Models\Shipment::FINAL_STATUSES, true);
    }

    // Sensible defaults so a minimal carrier only overrides what it actually does.

    public function validateAddress(CarrierAccount $account, array $address): array
    {
        return ['is_valid' => true, 'normalized' => $address, 'notes' => []];
    }

    public function getRates(CarrierAccount $account, RateRequest $request): array
    {
        $this->unsupported('rates');
    }

    public function cancelShipment(CarrierAccount $account, \App\Models\Shipment $shipment): bool
    {
        $this->unsupported('cancel');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        return [];
    }

    public function createReturnShipment(CarrierAccount $account, \App\Models\Shipment $shipment): array
    {
        $this->unsupported('return_label');
    }

    public function parseWebhook(array $payload, array $headers): array
    {
        return [];
    }
}
