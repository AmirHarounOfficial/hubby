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
use App\Services\Shipping\SoapCarrierClient;
use Illuminate\Support\Carbon;

/**
 * Aramex — the default cross-Gulf carrier and the flagship of "the wedge" (spec 04 §6.1). SOAP over
 * HTTPS; every call carries a ClientInfo block built from the account credentials. COD is a shipment
 * additional-service value + currency.
 *
 * VERIFICATION STATUS: implemented against the documented Aramex Shipping/Rate/Tracking SOAP shapes.
 * Exact operation names, endpoint hosts, and field names are the live WSDL's to confirm — this driver
 * is exercised by AramexCarrierTest with representative SOAP fixtures and MUST NOT be enabled in
 * production until captured request/response XML is recorded (docs/specs/carriers/aramex.md, §6.8 r4).
 */
class AramexCarrier extends BaseShippingCarrier
{
    protected array $capabilities = ['rates', 'cod', 'pickup', 'cancel', 'multi_package', 'address_validation'];

    private const RATE_URL = 'https://ws.aramex.net/ShippingAPI.V2/RateCalculator/Service_1_0.svc';
    private const SHIP_URL = 'https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc';
    private const TRACK_URL = 'https://ws.aramex.net/ShippingAPI.V2/Tracking/Service_1_0.svc';

    public function __construct(private readonly SoapCarrierClient $soap = new SoapCarrierClient())
    {
    }

    public function code(): string
    {
        return 'aramex';
    }

    public function validateCredentials(CarrierAccount $account): bool
    {
        // A cheap rate probe doubles as a credential check: a bad ClientInfo returns a fault/HasErrors.
        try {
            $xml = $this->soap->call(self::RATE_URL, 'CalculateRate', $this->rateBody($account, $this->probeRequest()));
        } catch (\RuntimeException $e) {
            throw new CarrierAuthException('Aramex rejected the account credentials: '.$e->getMessage());
        }

        return strtolower((string) ($xml->HasErrors ?? 'true')) === 'false';
    }

    public function getRates(CarrierAccount $account, RateRequest $request): array
    {
        $xml = $this->soap->call(self::RATE_URL, 'CalculateRate', $this->rateBody($account, $request));

        if (strtolower((string) ($xml->HasErrors ?? 'true')) !== 'false') {
            return [];
        }

        $amount = (float) ($xml->TotalAmount->Value ?? 0);
        if ($amount <= 0) {
            return [];
        }

        return [new CarrierRate(
            carrierCode: 'aramex',
            serviceCode: 'DOM',
            serviceName: 'Aramex Domestic',
            amount: $amount,
            currency: (string) ($xml->TotalAmount->CurrencyCode ?? $request->currency),
        )];
    }

    public function createShipment(CarrierAccount $account, Shipment $shipment): array
    {
        $xml = $this->soap->call(self::SHIP_URL, 'CreateShipments', $this->createBody($account, $shipment));

        if (strtolower((string) ($xml->HasErrors ?? 'true')) !== 'false') {
            throw new \RuntimeException('CARRIER_ERROR: '.($xml->Notifications->Notification->Message ?? 'Aramex rejected the shipment'));
        }

        $processed = $xml->Shipments->ProcessedShipment ?? null;
        $awb = (string) ($processed->ID ?? '');
        $labelUrl = (string) ($processed->ShipmentLabel->LabelURL ?? '');

        return [
            'tracking_number' => $awb,
            'carrier_shipment_id' => $awb,
            'packages' => [['sequence' => 1, 'tracking_number' => $awb]],
            'label' => $labelUrl ? ['format' => 'pdf', 'content_base64' => null, 'url' => $labelUrl] : null,
            'cost' => null,
            'estimated_delivery_at' => null,
            'raw' => ['awb' => $awb], // redacted: ClientInfo never persisted
        ];
    }

    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string
    {
        $this->unsupported('label_refetch');
    }

    public function track(CarrierAccount $account, string $trackingNumber): array
    {
        $xml = $this->soap->call(self::TRACK_URL, 'TrackShipments', $this->trackBody($account, $trackingNumber));

        $events = [];
        foreach ($xml->xpath('//TrackingResult') ?: [] as $r) {
            $status = $this->normalizeStatus((string) $r->UpdateDescription, (string) $r->UpdateCode);
            $events[] = new CarrierTrackingEvent(
                status: $status,
                eventAt: Carbon::parse((string) ($r->UpdateDateTime ?: 'now')),
                rawStatus: (string) $r->UpdateDescription,
                rawCode: (string) $r->UpdateCode,
                descriptionEn: (string) $r->UpdateDescription,
                location: (string) $r->UpdateLocation ?: null,
                isException: in_array($status, ['held', 'exception', 'returned_to_origin'], true),
                payload: ['source' => 'poll'],
            );
        }

        return $events;
    }

    // --- SOAP request bodies -----------------------------------------------------------------

    private function clientInfo(CarrierAccount $account): string
    {
        $c = $account->credentials ?? [];

        return '<ClientInfo>'
            .$this->el('UserName', $c['username'] ?? '')
            .$this->el('Password', $c['password'] ?? '')
            .$this->el('Version', 'v1.0')
            .$this->el('AccountNumber', $c['account_number'] ?? $account->account_number)
            .$this->el('AccountPin', $c['account_pin'] ?? '')
            .$this->el('AccountEntity', $c['account_entity'] ?? '')
            .$this->el('AccountCountryCode', $c['account_country_code'] ?? 'SA')
            .'</ClientInfo>';
    }

    private function rateBody(CarrierAccount $account, RateRequest $r): string
    {
        return '<CalculateRate xmlns="http://ws.aramex.net/ShippingAPI/v1/">'
            .$this->clientInfo($account)
            .'<OriginAddress>'.$this->el('City', $r->from->city).$this->el('CountryCode', $r->from->countryCode).'</OriginAddress>'
            .'<DestinationAddress>'.$this->el('City', $r->to->city).$this->el('CountryCode', $r->to->countryCode).'</DestinationAddress>'
            .'<ShipmentDetails>'.$this->el('ActualWeight', (string) $r->totalWeightKg())
            .$this->el('CashOnDeliveryAmount', (string) ($r->isCod ? $r->codAmount : 0)).'</ShipmentDetails>'
            .'</CalculateRate>';
    }

    private function createBody(CarrierAccount $account, Shipment $shipment): string
    {
        $from = $shipment->shipFromAddress ? AddressData::fromModel($shipment->shipFromAddress) : new AddressData(countryCode: 'SA');
        $to = $shipment->shipToAddress ? AddressData::fromModel($shipment->shipToAddress) : new AddressData(countryCode: 'SA');
        $cod = $shipment->is_cod
            ? '<Services>CashOnDelivery</Services>'.$this->el('CashOnDeliveryAmount', (string) $shipment->cod_amount)
            : '';

        return '<CreateShipments xmlns="http://ws.aramex.net/ShippingAPI/v1/">'
            .$this->clientInfo($account)
            .'<Shipments><Shipment>'
            .'<Consignee>'.$this->el('City', $to->city).$this->el('CountryCode', $to->countryCode).$this->el('PersonName', $to->name).'</Consignee>'
            .'<Shipper>'.$this->el('City', $from->city).$this->el('CountryCode', $from->countryCode).'</Shipper>'
            .'<Details>'.$this->el('ActualWeight', (string) $shipment->total_weight_kg).$this->el('DescriptionOfGoods', $shipment->contents_description ?: 'Goods').$cod.'</Details>'
            .'</Shipment></Shipments>'
            .'</CreateShipments>';
    }

    private function trackBody(CarrierAccount $account, string $trackingNumber): string
    {
        return '<TrackShipments xmlns="http://ws.aramex.net/ShippingAPI/v1/">'
            .$this->clientInfo($account)
            .'<Shipments><string>'.htmlspecialchars($trackingNumber).'</string></Shipments>'
            .'</TrackShipments>';
    }

    private function probeRequest(): RateRequest
    {
        return new RateRequest(
            from: new AddressData(city: 'Riyadh', countryCode: 'SA'),
            to: new AddressData(city: 'Jeddah', countryCode: 'SA'),
            packages: [new \App\Services\Shipping\Data\PackageData(weightKg: 1.0)],
        );
    }

    private function el(string $tag, ?string $value): string
    {
        return '<'.$tag.'>'.htmlspecialchars((string) $value).'</'.$tag.'>';
    }
}
