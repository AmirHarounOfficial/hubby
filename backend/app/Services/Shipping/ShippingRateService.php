<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\ShippingRate;
use App\Services\Shipping\Data\CarrierRate;
use App\Services\Shipping\Data\RateRequest;

/**
 * Rate shopping across a merchant's carrier accounts (spec 04 §4.3).
 *
 * A dead or slow carrier is OMITTED, never fatal — one carrier must never block the rate modal; the
 * result carries an errors[] so the UI can say "SMSA didn't respond". Results are cached by
 * request_hash (a merchant reopening the modal shouldn't re-hammer carriers) and ranked cheapest
 * first. Carriers with no rate API return is_estimate rows from a per-account rate_table, which the
 * UI must clearly mark — a guessed price shown as a quote destroys trust.
 *
 * NB: the spec calls for a concurrent Http::pool() fan-out for latency; this slice fans out
 * sequentially with a per-call timeout. Correctness is identical; parallelism is a latency follow-up.
 */
class ShippingRateService
{
    /**
     * @param array<int, CarrierAccount> $accounts
     * @return array{request_hash:string,expires_at:string,rates:array<int,ShippingRate>,errors:array<int,array<string,string>>}
     */
    public function shop(int $organizationId, RateRequest $request, array $accounts, ?int $orderId = null, bool $refresh = false): array
    {
        $hash = $request->requestHash();

        if (! $refresh) {
            $cached = ShippingRate::where('request_hash', $hash)
                ->where('organization_id', $organizationId)
                ->where('expires_at', '>', now())
                ->orderBy('rank')
                ->get();
            if ($cached->isNotEmpty()) {
                return $this->wrap($hash, $cached, []);
            }
        }

        $quotes = [];   // [CarrierRate, CarrierAccount]
        $errors = [];

        foreach ($accounts as $account) {
            if (! $account->is_active) {
                continue;
            }

            $carrier = CarrierFactory::make($account->carrier_code);

            if ($carrier->supports('rates')) {
                try {
                    foreach ($carrier->getRates($account, $request) as $rate) {
                        $quotes[] = [$rate, $account];
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['carrier_code' => $account->carrier_code, 'code' => 'CARRIER_ERROR', 'message' => $e->getMessage()];
                }
            } elseif ($table = $account->settings['rate_table'] ?? null) {
                if ($estimate = $this->estimateFromTable($request, $account, $table)) {
                    $quotes[] = [$estimate, $account];
                }
            }
        }

        return $this->persistRanked($organizationId, $orderId, $hash, $quotes, $errors);
    }

    /** Cheapest first (tie-break: faster max transit), rank 1 recommended. */
    private function persistRanked(int $organizationId, ?int $orderId, string $hash, array $quotes, array $errors): array
    {
        usort($quotes, function ($a, $b) {
            $cmp = $a[0]->totalAmount() <=> $b[0]->totalAmount();

            return $cmp !== 0 ? $cmp : (($a[0]->transitDaysMax ?? 99) <=> ($b[0]->transitDaysMax ?? 99));
        });

        // Fresh shop supersedes any stale rows for this hash.
        ShippingRate::where('request_hash', $hash)->where('organization_id', $organizationId)->delete();

        $expiresAt = now()->addMinutes((int) config('shipping.rate_ttl_minutes', 30));
        $rows = [];

        foreach ($quotes as $i => [$rate, $account]) {
            /** @var CarrierRate $rate */
            $rows[] = ShippingRate::create([
                'organization_id' => $organizationId,
                'order_id' => $orderId,
                'request_hash' => $hash,
                'carrier_account_id' => $account->id,
                'carrier_code' => $rate->carrierCode,
                'service_code' => $rate->serviceCode,
                'service_name' => $rate->serviceName,
                'amount' => $rate->amount,
                'currency' => $rate->currency,
                'cod_fee' => $rate->codFee,
                'fuel_surcharge' => $rate->fuelSurcharge,
                'vat_amount' => $rate->vatAmount,
                'total_amount' => $rate->totalAmount(),
                'transit_days_min' => $rate->transitDaysMin,
                'transit_days_max' => $rate->transitDaysMax,
                'is_estimate' => $rate->isEstimate,
                'rank' => $i + 1,
                'expires_at' => $expiresAt,
                'raw' => $rate->raw,
            ]);
        }

        return $this->wrap($hash, collect($rows), $errors);
    }

    private function estimateFromTable(RateRequest $request, CarrierAccount $account, array $table): ?CarrierRate
    {
        // A rate_table is a flat { service_code, service_name, per_kg, base, currency } estimate.
        $perKg = (float) ($table['per_kg'] ?? 0);
        $base = (float) ($table['base'] ?? 0);
        $amount = round($base + $perKg * $request->totalWeightKg(), 2);
        if ($amount <= 0) {
            return null;
        }

        return new CarrierRate(
            carrierCode: $account->carrier_code,
            serviceCode: (string) ($table['service_code'] ?? 'STD'),
            serviceName: $table['service_name'] ?? 'Estimated',
            amount: $amount,
            currency: (string) ($table['currency'] ?? $request->currency),
            isEstimate: true,
        );
    }

    private function wrap(string $hash, $rows, array $errors): array
    {
        return [
            'request_hash' => $hash,
            'expires_at' => optional($rows->first())->expires_at?->toIso8601String()
                ?? now()->addMinutes((int) config('shipping.rate_ttl_minutes', 30))->toIso8601String(),
            'rates' => $rows->values()->all(),
            'errors' => $errors,
        ];
    }
}
