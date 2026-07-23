<?php

namespace App\Services\Shipping\Carriers;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Services\Shipping\BaseShippingCarrier;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\Exceptions\CarrierAuthException;
use App\Services\Shipping\SoapCarrierClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * SMSA Express — the domestic Saudi workhorse (spec 04 §6.2). Between SMSA and Aramex you cover the
 * majority of Saudi e-commerce parcels.
 *
 * TWO drivers behind ONE class. SMSA has been migrating merchants from the legacy SECOM ASMX SOAP
 * service (auth: `passkey`) to a newer REST API (auth: API-key header), and which surface a merchant
 * gets depends on their contract/onboarding date. `credentials.mode` ('secom_soap' | 'rest') selects
 * per account — assuming one surface for everyone means half of SMSA customers can't connect.
 *
 * No rate API on the legacy surface, so SMSA advertises no 'rates' capability and rate shopping uses
 * the is_estimate rate_table fallback (§4.3).
 *
 * VERIFICATION STATUS: implemented against documented shapes, fixture-tested (SmsaCarrierTest, both
 * modes). NOT production-enabled until a real addShipment/getStatus capture per mode is recorded
 * (docs/specs/carriers/smsa.md, §6.8 r4).
 */
class SmsaCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['cod', 'cancel'];

    private const SOAP_ENDPOINT = 'https://track.smsaexpress.com/SECOM/SMSAwebService.asmx';
    private const SOAP_NS = 'http://track.smsaexpress.com/';
    private const REST_BASE = 'https://ecomapis.smsaexpress.com';

    public function __construct(private readonly SoapCarrierClient $soap = new SoapCarrierClient())
    {
    }

    public function code(): string
    {
        return 'smsa';
    }

    private function mode(CarrierAccount $account): string
    {
        return ($account->credentials['mode'] ?? 'secom_soap') === 'rest' ? 'rest' : 'secom_soap';
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        return $this->mode($account) === 'rest'
            ? $this->restValidate($account)
            : $this->soapValidate($account);
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        return $this->mode($account) === 'rest'
            ? $this->restCreate($account, $shipment)
            : $this->soapCreate($account, $shipment);
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        $this->unsupported('label_refetch');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        return $this->mode($account) === 'rest'
            ? $this->restTrack($account, $trackingNumber)
            : $this->soapTrack($account, $trackingNumber);
    }

    // --- SECOM SOAP driver -------------------------------------------------------------------

    private function passkey(CarrierAccount $account): string
    {
        return (string) ($account->credentials['passkey'] ?? '');
    }

    private function soapValidate(CarrierAccount $account): bool
    {
        try {
            $this->soap->call(self::SOAP_ENDPOINT, self::SOAP_NS.'getStatus', $this->soapBody('getStatus', [
                'awbNo' => '0', 'passKey' => $this->passkey($account),
            ]));
        } catch (\RuntimeException $e) {
            if (stripos($e->getMessage(), 'passkey') !== false || stripos($e->getMessage(), 'auth') !== false) {
                throw new CarrierAuthException('SMSA rejected the passKey.');
            }
        }

        return true;
    }

    private function soapCreate(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $xml = $this->soap->call(self::SOAP_ENDPOINT, self::SOAP_NS.'addShipment', $this->soapBody('addShipment', [
            'passKey' => $this->passkey($account),
            'refNo' => $shipment->reference,
            'cName' => $to->name ?? '',
            'cntry' => $to->countryCode,
            'cCity' => $to->city ?? '',
            'cMobile' => $to->phone ?? '',
            'cAddr1' => $to->line1 ?? '',
            'shipType' => 'DLV',
            'PCs' => (string) max(1, $shipment->package_count),
            'codAmt' => (string) ($shipment->is_cod ? $shipment->cod_amount : 0),
            'weight' => (string) $shipment->total_weight_kg,
            'itemDesc' => $shipment->contents_description ?: 'Goods',
        ]));

        $awb = trim((string) ($xml->addShipmentResult ?? ''));
        if ($awb === '' || ! ctype_alnum($awb)) {
            throw new \RuntimeException('CARRIER_ERROR: SMSA did not return an AWB ('.$awb.')');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => $this->soapLabel($account, $awb), 'url' => null],
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['awb' => $awb], // passKey never persisted
        ];
    }

    private function soapLabel(CarrierAccount $account, string $awb): ?string
    {
        try {
            $xml = $this->soap->call(self::SOAP_ENDPOINT, self::SOAP_NS.'getPDF', $this->soapBody('getPDF', [
                'awbNo' => $awb, 'passKey' => $this->passkey($account),
            ]));

            $b64 = trim((string) ($xml->getPDFResult ?? ''));

            return $b64 !== '' ? $b64 : null;
        } catch (\Throwable $e) {
            return null; // label fetch is best-effort; the AWB already exists
        }
    }

    private function soapTrack(CarrierAccount $account, string $trackingNumber): array
    {
        $xml = $this->soap->call(self::SOAP_ENDPOINT, self::SOAP_NS.'getStatus', $this->soapBody('getStatus', [
            'awbNo' => $trackingNumber, 'passKey' => $this->passkey($account),
        ]));

        $events = [];
        foreach ($xml->xpath('//Item') ?: [] as $item) {
            $status = $this->normalizeStatus((string) $item->Status, (string) ($item->Code ?? ''));
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse((string) ($item->Date ?: 'now')),
                rawStatus: (string) $item->Status,
                descriptionEn: (string) $item->Status,
                location: (string) ($item->Location ?: '') ?: null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }

    /** Build an ASMX SOAP method body with a namespace + simple string params. */
    private function soapBody(string $op, array $params): string
    {
        $inner = '';
        foreach ($params as $k => $v) {
            $inner .= '<'.$k.'>'.htmlspecialchars((string) $v).'</'.$k.'>';
        }

        return '<'.$op.' xmlns="'.self::SOAP_NS.'">'.$inner.'</'.$op.'>';
    }

    // --- Newer REST driver -------------------------------------------------------------------

    private function restClient(CarrierAccount $account)
    {
        $base = $account->credentials['base_url'] ?? self::REST_BASE;

        return Http::baseUrl(rtrim($base, '/'))
            ->connectTimeout(10)->timeout(30)->acceptJson()
            ->withHeaders(['apikey' => (string) ($account->credentials['api_key'] ?? '')]);
    }

    private function restValidate(CarrierAccount $account): bool
    {
        $response = $this->restClient($account)->get('/api/track/000000000');

        if ($response->status() === 401 || $response->status() === 403) {
            throw new CarrierAuthException('SMSA rejected the API key.');
        }

        return true;
    }

    private function restCreate(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $response = $this->restClient($account)->post('/api/shipment', [
            'ConsigneeName' => $to->name,
            'ConsigneeMobile' => $to->phone,
            'ConsigneeCity' => $to->city,
            'ConsigneeCountry' => $to->countryCode,
            'ConsigneeAddress' => $to->line1,
            'Reference' => $shipment->reference,
            'Pieces' => max(1, $shipment->package_count),
            'Weight' => (float) $shipment->total_weight_kg,
            'CODAmount' => $shipment->is_cod ? (float) $shipment->cod_amount : 0,
            'Description' => $shipment->contents_description ?: 'Goods',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('CARRIER_ERROR: '.$response->status());
        }

        $body = $response->json();
        $awb = (string) ($body['awbNo'] ?? $body['sawb'] ?? '');
        if ($awb === '') {
            throw new \RuntimeException('CARRIER_ERROR: SMSA did not return an AWB');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => $body['label'] ?? null, 'url' => $body['labelUrl'] ?? null],
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['awb' => $awb],
        ];
    }

    private function restTrack(CarrierAccount $account, string $trackingNumber): array
    {
        $response = $this->restClient($account)->get('/api/track/'.$trackingNumber);
        if ($response->failed()) {
            return [];
        }

        $events = [];
        foreach ($response->json('events') ?? $response->json('Activities') ?? [] as $e) {
            $status = $this->normalizeStatus($e['status'] ?? $e['Description'] ?? null, $e['code'] ?? null);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse($e['date'] ?? $e['Date'] ?? 'now'),
                rawStatus: $e['status'] ?? $e['Description'] ?? null,
                descriptionEn: $e['status'] ?? $e['Description'] ?? null,
                location: $e['location'] ?? $e['City'] ?? null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }
}
