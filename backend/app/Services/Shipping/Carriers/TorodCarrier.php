<?php

namespace App\Services\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Shipping\BaseShippingCarrier;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\Exceptions\CarrierAuthException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Torod — a Saudi shipping AGGREGATOR (spec 04 §6.5). One integration plausibly yields many carriers
 * (SMSA, Aramex, iMile…) for merchants who already have a Torod account. The actual carrier comes
 * back in the response and is stored in shipments.underlying_carrier ("SMSA via Torod").
 *
 * VERIFICATION STATUS: no public API reference was found — assumed REST + bearer token. Every
 * endpoint shape is UNVERIFIED until partner docs land; fixture-tested (TorodCarrierTest), not
 * production-enabled until a captured response is recorded (docs/specs/carriers/torod.md, §6.8 r4).
 */
class TorodCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['cod', 'cancel'];

    private const BASE = 'https://api.torod.co';

    private function client(CarrierAccount $account)
    {
        $base = $account->credentials['base_url'] ?? self::BASE;

        return Http::baseUrl(rtrim($base, '/'))->connectTimeout(10)->timeout(30)->acceptJson()
            ->withToken((string) ($account->credentials['api_token'] ?? ''));
    }

    public function code(): string
    {
        return 'torod';
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        $response = $this->client($account)->get('/api/v1/account');
        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierAuthException('Torod rejected the API token.');
        }

        return true;
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $response = $this->client($account)->post('/api/v1/shipments', [
            'reference' => $shipment->reference,
            'consignee' => ['name' => $to->name, 'phone' => $to->phone, 'city' => $to->city, 'country' => $to->countryCode, 'address' => $to->line1],
            'weight' => (float) $shipment->total_weight_kg,
            'cod_amount' => $shipment->is_cod ? (float) $shipment->cod_amount : 0,
            'description' => $shipment->contents_description ?: 'Goods',
            'preferred_courier' => $account->settings['preferred_courier'] ?? null,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('CARRIER_ERROR: '.$response->status());
        }

        $body = $response->json();
        $awb = (string) ($body['awb'] ?? $body['tracking_number'] ?? '');
        if ($awb === '') {
            throw new \RuntimeException('CARRIER_ERROR: Torod did not return an AWB');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => (string) ($body['id'] ?? $awb),
            'underlying_carrier' => $body['courier'] ?? $body['carrier'] ?? null,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => $body['label'] ?? null, 'url' => $body['label_url'] ?? null],
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['awb' => $awb, 'courier' => $body['courier'] ?? null],
        ];
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        $this->unsupported('label_refetch');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        $response = $this->client($account)->get('/api/v1/shipments/'.$trackingNumber.'/tracking');
        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach ($response->json('events') ?? [] as $e) {
            $status = $this->normalizeStatus($e['status'] ?? null, $e['code'] ?? null);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse($e['date'] ?? 'now'),
                rawStatus: $e['status'] ?? null,
                descriptionEn: $e['status'] ?? null,
                location: $e['city'] ?? null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }
}
