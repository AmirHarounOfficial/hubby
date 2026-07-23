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
use Illuminate\Support\Facades\Log;

/**
 * DHL Express via the MyDHL API (spec 04 §6.6). HTTP Basic auth, sent pre-emptively.
 *
 * VERIFICATION STATUS: implemented against the documented MyDHL API shapes (developer.dhl.com). The
 * exact resource paths and field names are the portal's to confirm — this driver is exercised by
 * DhlCarrierTest with representative fixtures and MUST NOT be enabled in production until a real
 * sandbox response is captured into docs/specs/carriers/dhl.md (spec §6.8 rule 4). No DHL-specific
 * logic lives outside this class (§6.8 rule 1); credentials are redacted before any persistence.
 */
class DhlCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['rates', 'multi_package', 'pickup', 'cancel', 'address_validation', 'zpl'];

    public function code(): string
    {
        return 'dhl';
    }

    private function baseUrl(CarrierAccount $account): string
    {
        return $account->environment === 'production'
            ? 'https://express.api.dhl.com/mydhlapi'
            : 'https://express.api.dhl.com/mydhlapi/test';
    }

    private function client(CarrierAccount $account)
    {
        $creds = $account->credentials ?? [];

        return $this->getHttpClient($account)
            ->withBasicAuth((string) ($creds['api_key'] ?? ''), (string) ($creds['api_secret'] ?? ''))
            ->withHeaders(['Message-Reference' => (string) \Illuminate\Support\Str::uuid()]);
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        $response = $this->client($account)->get($this->baseUrl($account).'/products', [
            'accountNumber' => $account->account_number,
        ]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierAuthException('DHL rejected the account credentials.');
        }

        return $response->successful();
    }

    public function getRates(CarrierAccount $account, RateRequest $request): array
    {
        $response = $this->client($account)->post($this->baseUrl($account).'/rates', [
            'customerDetails' => [
                'shipperDetails' => $this->addressBlock($request->from),
                'receiverDetails' => $this->addressBlock($request->to),
            ],
            'accounts' => [['typeCode' => 'shipper', 'number' => $account->account_number]],
            'plannedShippingDateAndTime' => now()->addDay()->format('Y-m-d\TH:i:s\G\M\TP'),
            'unitOfMeasurement' => 'metric',
            'isCustomsDeclarable' => $request->to->countryCode !== $request->from->countryCode,
            'packages' => $this->packageBlocks($request),
        ]);

        if ($response->failed()) {
            Log::warning('DHL getRates failed', ['status' => $response->status()]);

            return [];
        }

        $rates = [];
        foreach ($response->json('products') ?? [] as $product) {
            $price = collect($product['totalPrice'] ?? [])->firstWhere('priceCurrency', $request->currency)
                ?? ($product['totalPrice'][0] ?? null);
            if (! $price) {
                continue;
            }

            $transit = data_get($product, 'deliveryCapabilities.totalTransitDays');
            $rates[] = new CarrierRate(
                carrierCode: 'dhl',
                serviceCode: (string) ($product['productCode'] ?? 'EXP'),
                serviceName: $product['productName'] ?? 'DHL Express',
                amount: (float) ($price['price'] ?? 0),
                currency: (string) ($price['priceCurrency'] ?? $request->currency),
                transitDaysMin: $transit !== null ? (int) $transit : null,
                transitDaysMax: $transit !== null ? (int) $transit : null,
                raw: $product,
            );
        }

        return $rates;
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $response = $this->client($account)->post($this->baseUrl($account).'/shipments', [
            'plannedShippingDateAndTime' => now()->addDay()->format('Y-m-d\TH:i:s\G\M\TP'),
            'productCode' => $shipment->service_code ?: 'P',
            'accounts' => [['typeCode' => 'shipper', 'number' => $account->account_number]],
            'customerDetails' => [
                'shipperDetails' => $this->addressBlock($shipment->shipFromAddress ? AddressData::fromModel($shipment->shipFromAddress) : null),
                'receiverDetails' => $this->addressBlock($shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : null),
            ],
            'content' => [
                'unitOfMeasurement' => 'metric',
                'isCustomsDeclarable' => (bool) $shipment->incoterm,
                'description' => $shipment->contents_description ?: 'Goods',
                'packages' => $shipment->packages->map(fn ($p) => [
                    'weight' => (float) $p->weight_kg,
                    'dimensions' => [
                        'length' => (float) ($p->length_cm ?? 1),
                        'width' => (float) ($p->width_cm ?? 1),
                        'height' => (float) ($p->height_cm ?? 1),
                    ],
                ])->all(),
            ],
            'outputImageProperties' => [
                'imageOptions' => [['typeCode' => 'label', 'templateName' => 'ECOM26_84_A4_001', 'imageFormat' => 'PDF']],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('CARRIER_ERROR: '.$response->status());
        }

        $body = $response->json();
        $label = collect($body['documents'] ?? [])->firstWhere('typeCode', 'label');

        return [
            'tracking_number' => (string) ($body['shipmentTrackingNumber'] ?? ''),
            'carrier_shipment_id' => $body['shipmentTrackingNumber'] ?? null,
            'packages' => collect($body['packages'] ?? [])->map(fn ($p, $i) => [
                'sequence' => $i + 1,
                'tracking_number' => $p['trackingNumber'] ?? null,
            ])->all(),
            'label' => $label ? [
                'format' => strtolower($label['imageFormat'] ?? 'pdf'),
                'content_base64' => $label['content'] ?? null,
                'url' => null,
            ] : null,
            'cost' => null,
            'estimated_delivery_at' => data_get($body, 'estimatedDeliveryDate.estimatedDeliveryDate'),
            'raw' => $this->redact($body),
        ];
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        // MyDHL returns the label inline at creation; a re-fetch is the tracking-document endpoint.
        $this->unsupported('label_refetch');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        $response = $this->client($account)->get($this->baseUrl($account).'/tracking', [
            'shipmentTrackingNumber' => $trackingNumber,
        ]);

        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach ($response->json('shipments.0.events') ?? [] as $e) {
            $at = trim(($e['date'] ?? '').' '.($e['time'] ?? ''));
            $status = $this->normalizeStatus($e['description'] ?? null, $e['typeCode'] ?? null);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse($at ?: 'now'),
                rawStatus: $e['description'] ?? null,
                rawCode: $e['typeCode'] ?? null,
                descriptionEn: $e['description'] ?? null,
                location: data_get($e, 'serviceArea.0.description'),
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }

    /** DHL address → MyDHL postalAddress + contactInformation block. Always takes an AddressData. */
    private function addressBlock(?AddressData $address): array
    {
        return [
            'postalAddress' => [
                'cityName' => $address?->city,
                'countryCode' => $address?->countryCode ?? 'SA',
                'postalCode' => $address?->postalCode,
                'addressLine1' => $address?->line1,
            ],
            'contactInformation' => [
                'fullName' => $address?->name,
                'companyName' => $address?->company ?? $address?->name,
                'phone' => $address?->phone,
                'email' => $address?->email,
            ],
        ];
    }

    private function packageBlocks(RateRequest $request): array
    {
        return array_map(fn ($p) => [
            'weight' => $p->weightKg,
            'dimensions' => [
                'length' => $p->lengthCm ?? 1,
                'width' => $p->widthCm ?? 1,
                'height' => $p->heightCm ?? 1,
            ],
        ], $request->packages);
    }

    /** Strip anything sensitive before a raw blob is persisted (§6.8 rule 5). */
    private function redact(array $body): array
    {
        unset($body['Authorization'], $body['password'], $body['client_secret']);

        return $body;
    }
}
