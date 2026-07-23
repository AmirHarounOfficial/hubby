<?php

namespace App\Services\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Shipping\BaseShippingCarrier;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\CarrierRate;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\Data\RateRequest;
use App\Services\Shipping\Exceptions\CarrierAuthException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * FedEx (spec 04 §6.7). REST + OAuth2 client_credentials: exchange Client ID + Secret at /oauth/token
 * for a short-lived bearer token. Lowest strategic priority, included because enterprise merchants
 * ask and because it validates that the interface handles OAuth carriers (Aramex/SMSA/Naqel don't).
 *
 * The access token is short-lived, so it's cached per carrier_account (TTL = expires_in − 60s) rather
 * than re-fetched per call — the same pattern integrations use for token refresh.
 *
 * VERIFICATION STATUS: implemented against the documented OAuth + Ship/Track shapes; exact per-version
 * schemas UNVERIFIED. Fixture-tested (FedexCarrierTest), not production-enabled until a captured
 * sandbox response is recorded (docs/specs/carriers/fedex.md, §6.8 r4).
 */
class FedexCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['rates', 'cancel', 'multi_package'];

    private function baseUrl(CarrierAccount $account): string
    {
        return $account->environment === 'production'
            ? 'https://apis.fedex.com'
            : 'https://apis-sandbox.fedex.com';
    }

    public function code(): string
    {
        return 'fedex';
    }

    /** Bearer token, cached per account until just before it expires (single source of truth). */
    private function token(CarrierAccount $account): string
    {
        return Cache::remember("fedex:token:{$account->id}", now()->addMinutes(50), function () use ($account) {
            $c = $account->credentials ?? [];
            $response = Http::asForm()->post($this->baseUrl($account).'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $c['client_id'] ?? '',
                'client_secret' => $c['client_secret'] ?? '',
            ]);

            if ($response->status() === 401 || $response->failed()) {
                throw new CarrierAuthException('FedEx rejected the client credentials.');
            }

            return (string) $response->json('access_token');
        });
    }

    private function client(CarrierAccount $account)
    {
        return Http::baseUrl($this->baseUrl($account))->connectTimeout(10)->timeout(30)->acceptJson()
            ->withToken($this->token($account));
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        $this->token($account); // throws CarrierAuthException on bad creds

        return true;
    }

    public function getRates(CarrierAccount $account, RateRequest $request): array
    {
        $response = $this->client($account)->post('/rate/v1/rates/quotes', [
            'accountNumber' => ['value' => $account->account_number],
            'requestedShipment' => [
                'shipper' => ['address' => ['city' => $request->from->city, 'countryCode' => $request->from->countryCode]],
                'recipient' => ['address' => ['city' => $request->to->city, 'countryCode' => $request->to->countryCode]],
                'requestedPackageLineItems' => array_map(fn ($p) => ['weight' => ['units' => 'KG', 'value' => $p->weightKg]], $request->packages),
            ],
        ]);

        if ($response->failed()) {
            return [];
        }

        $rates = [];
        foreach ($response->json('output.rateReplyDetails') ?? [] as $detail) {
            $amount = (float) data_get($detail, 'ratedShipmentDetails.0.totalNetCharge');
            if ($amount <= 0) {
                continue;
            }
            $rates[] = new CarrierRate(
                carrierCode: 'fedex',
                serviceCode: (string) ($detail['serviceType'] ?? 'FEDEX'),
                serviceName: $detail['serviceName'] ?? 'FedEx',
                amount: $amount,
                currency: (string) (data_get($detail, 'ratedShipmentDetails.0.currency') ?? $request->currency),
            );
        }

        return $rates;
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $response = $this->client($account)->post('/ship/v1/shipments', [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => ['value' => $account->account_number],
            'requestedShipment' => [
                'shipper' => ['contact' => ['personName' => 'Shipper'], 'address' => ['countryCode' => 'SA']],
                'recipients' => [['contact' => ['personName' => $to->name, 'phoneNumber' => $to->phone], 'address' => ['city' => $to->city, 'countryCode' => $to->countryCode]]],
                'serviceType' => $shipment->service_code ?: 'INTERNATIONAL_PRIORITY',
                'packagingType' => 'YOUR_PACKAGING',
                'requestedPackageLineItems' => [['weight' => ['units' => 'KG', 'value' => (float) $shipment->total_weight_kg]]],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('CARRIER_ERROR: '.$response->status());
        }

        $piece = data_get($response->json(), 'output.transactionShipments.0.pieceResponses.0');
        $awb = (string) ($piece['trackingNumber'] ?? data_get($response->json(), 'output.transactionShipments.0.masterTrackingNumber') ?? '');
        if ($awb === '') {
            throw new \RuntimeException('CARRIER_ERROR: FedEx did not return a tracking number');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => data_get($piece, 'packageDocuments.0.encodedLabel'), 'url' => null],
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['awb' => $awb],
        ];
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        $this->unsupported('label_refetch');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        $response = $this->client($account)->post('/track/v1/trackingnumbers', [
            'trackingInfo' => [['trackingNumberInfo' => ['trackingNumber' => $trackingNumber]]],
            'includeDetailedScans' => true,
        ]);

        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach (data_get($response->json(), 'output.completeTrackResults.0.trackResults.0.scanEvents') ?? [] as $e) {
            $status = $this->normalizeStatus($e['eventDescription'] ?? null, $e['eventType'] ?? null);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse($e['date'] ?? 'now'),
                rawStatus: $e['eventDescription'] ?? null,
                rawCode: $e['eventType'] ?? null,
                descriptionEn: $e['eventDescription'] ?? null,
                location: data_get($e, 'scanLocation.city'),
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }
}
