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
 * J&T Express (spec 04 §6.4). REST/JSON, requests signed with a digest:
 * base64(md5(json_body + private_key)), sent with an api_account identifier.
 *
 * STRUCTURALLY country-fragmented: the Indonesian, Saudi, Egyptian etc. operations do NOT share
 * endpoints, field names, or signing-version. credentials.country_code selects the base URL from
 * config/shipping.php — building a single "J&T API" and assuming it works region-wide is wrong.
 *
 * VERIFICATION STATUS: implemented against the documented signing scheme; per-country endpoint shapes
 * are UNVERIFIED. Fixture-tested (JntCarrierTest), not production-enabled until the launch country's
 * portal spec + a captured signed request are recorded (docs/specs/carriers/jnt.md, §6.8 r4).
 */
class JntCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['cod', 'cancel'];

    public function code(): string
    {
        return 'jnt';
    }

    private function baseUrl(CarrierAccount $account): string
    {
        $country = strtolower((string) ($account->credentials['country_code'] ?? 'sa'));

        return config("shipping.carriers.jnt.countries.{$country}")
            ?? $account->credentials['base_url']
            ?? 'https://api.jtexpress.sa';
    }

    /** J&T request signature: base64(md5(json + private_key)). */
    private function sign(string $json, string $privateKey): string
    {
        return base64_encode(md5($json.$privateKey, true));
    }

    private function post(CarrierAccount $account, string $path, array $payload)
    {
        $c = $account->credentials ?? [];
        $json = json_encode($payload);

        return Http::baseUrl($this->baseUrl($account))->connectTimeout(10)->timeout(30)->acceptJson()
            ->withHeaders([
                'apiAccount' => (string) ($c['api_account'] ?? ''),
                'digest' => $this->sign($json, (string) ($c['private_key'] ?? '')),
            ])
            ->withBody($json, 'application/json')
            ->post($path);
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        $response = $this->post($account, '/api/order/query', ['billCode' => '0']);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierAuthException('J&T rejected the credentials.');
        }

        return true;
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $response = $this->post($account, '/api/order/create', [
            'txlogisticId' => $shipment->reference,
            'receiver' => ['name' => $to->name, 'mobile' => $to->phone, 'city' => $to->city, 'countryCode' => $to->countryCode, 'address' => $to->line1],
            'weight' => (float) $shipment->total_weight_kg,
            'itemsValue' => (float) $shipment->declared_value,
            'codMoney' => $shipment->is_cod ? (float) $shipment->cod_amount : 0,
            'goodsName' => $shipment->contents_description ?: 'Goods',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('CARRIER_ERROR: '.$response->status());
        }

        $body = $response->json();
        $awb = (string) ($body['billCode'] ?? data_get($body, 'data.billCode') ?? '');
        if ($awb === '') {
            throw new \RuntimeException('CARRIER_ERROR: J&T did not return a billCode');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => data_get($body, 'data.label'), 'url' => null],
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
        $response = $this->post($account, '/api/logistics/trace', ['billCode' => $trackingNumber]);
        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach ($response->json('details') ?? data_get($response->json(), 'data.details') ?? [] as $e) {
            $status = $this->normalizeStatus($e['scanTypeName'] ?? $e['desc'] ?? null, $e['scanType'] ?? null);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse($e['scanTime'] ?? $e['acceptTime'] ?? 'now'),
                rawStatus: $e['scanTypeName'] ?? $e['desc'] ?? null,
                descriptionEn: $e['desc'] ?? $e['scanTypeName'] ?? null,
                location: $e['scanNetworkCity'] ?? $e['city'] ?? null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }
}
