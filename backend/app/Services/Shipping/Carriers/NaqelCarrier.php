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

/**
 * Naqel Express — the third domestic Saudi option (spec 04 §6.3). SOAP over HTTPS; auth is a
 * client id + password pair. Matters most for merchants with negotiated Naqel rates; cheap now that
 * the SOAP client exists.
 *
 * VERIFICATION STATUS: implemented against the documented shape (official Naqel PDF is the source of
 * truth). Operation names, hosts and field names are UNVERIFIED — fixture-tested (NaqelCarrierTest),
 * not production-enabled until a captured create-waybill response is recorded
 * (docs/specs/carriers/naqel.md, §6.8 r4). No rate API confirmed → estimate rate_table fallback.
 */
class NaqelCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['cod', 'cancel', 'pickup'];

    private const ENDPOINT = 'https://api.naqelexpress.com/Track/GatewayWSv31.svc';
    private const NS = 'http://tempuri.org/';

    public function __construct(private readonly SoapCarrierClient $soap = new SoapCarrierClient())
    {
    }

    public function code(): string
    {
        return 'naqel';
    }

    private function auth(CarrierAccount $account): string
    {
        $c = $account->credentials ?? [];

        return $this->el('clientID', $c['client_id'] ?? '').$this->el('password', $c['password'] ?? '');
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        try {
            $this->soap->call(self::ENDPOINT, self::NS.'GetWaybseStatus', '<GetWaybseStatus xmlns="'.self::NS.'">'.$this->auth($account).$this->el('waybillNo', '0').'</GetWaybseStatus>');
        } catch (\RuntimeException $e) {
            if (stripos($e->getMessage(), 'auth') !== false || stripos($e->getMessage(), 'password') !== false) {
                throw new CarrierAuthException('Naqel rejected the credentials.');
            }
        }

        return true;
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');

        $xml = $this->soap->call(self::ENDPOINT, self::NS.'CreateWaybill',
            '<CreateWaybill xmlns="'.self::NS.'">'.$this->auth($account)
            .$this->el('ConsigneeName', $to->name).$this->el('ConsigneeCity', $to->city).$this->el('ConsigneeMobile', $to->phone)
            .$this->el('Weight', (string) $shipment->total_weight_kg)
            .$this->el('CODCharge', (string) ($shipment->is_cod ? $shipment->cod_amount : 0))
            .$this->el('DeclareValue', (string) $shipment->declared_value)
            .$this->el('PieceDescription', $shipment->contents_description ?: 'Goods')
            .'</CreateWaybill>');

        $awb = trim((string) ($xml->CreateWaybillResult->WaybillNo ?? $xml->WaybillNo ?? ''));
        if ($awb === '') {
            throw new \RuntimeException('CARRIER_ERROR: Naqel did not return a waybill');
        }

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => ['format' => 'pdf', 'content_base64' => (string) ($xml->CreateWaybillResult->Label ?? '') ?: null, 'url' => null],
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
        $xml = $this->soap->call(self::ENDPOINT, self::NS.'GetWaybseStatus',
            '<GetWaybseStatus xmlns="'.self::NS.'">'.$this->auth($account).$this->el('waybillNo', $trackingNumber).'</GetWaybseStatus>');

        $events = [];
        foreach ($xml->xpath('//WaybillStatus') ?: [] as $s) {
            $status = $this->normalizeStatus((string) $s->Status, (string) ($s->Code ?? ''));
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse((string) ($s->Date ?: 'now')),
                rawStatus: (string) $s->Status,
                descriptionEn: (string) $s->Status,
                location: (string) ($s->City ?: '') ?: null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }

    private function el(string $tag, ?string $value): string
    {
        return '<'.$tag.'>'.htmlspecialchars((string) $value).'</'.$tag.'>';
    }
}
