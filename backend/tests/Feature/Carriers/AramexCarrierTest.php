<?php

namespace Tests\Feature\Carriers;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Carriers\AramexCarrier;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\PackageData;
use App\Services\Shipping\Data\RateRequest;
use Database\Seeders\CarrierStatusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aramex SOAP contract test against representative fixtures (spec 04 §6.1/§6.8). Proves the SOAP
 * transport + the abstraction: rate → CarrierRate, create → AWB + label URL, track → normalized
 * events, bad ClientInfo → CarrierAuthException. Fixtures are representative; a live WSDL capture is
 * required before enabling Aramex in production.
 */
class AramexCarrierTest extends TestCase
{
    use RefreshDatabase;

    private CarrierAccount $account;
    private AramexCarrier $aramex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CarrierStatusMapSeeder::class);
        $owner = User::factory()->create();
        $org = $this->makeOrganization($owner);
        $this->account = CarrierAccount::create([
            'organization_id' => $org->id, 'carrier_code' => 'aramex', 'label' => 'Aramex',
            'environment' => 'sandbox', 'account_number' => '12345',
            'credentials' => ['username' => 'u', 'password' => 'p', 'account_number' => '12345', 'account_pin' => '1', 'account_entity' => 'RUH', 'account_country_code' => 'SA'],
        ]);
        $this->aramex = new AramexCarrier();
    }

    private function soap(string $bodyInner): string
    {
        return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'.$bodyInner.'</soap:Body></soap:Envelope>';
    }

    private function rateRequest(): RateRequest
    {
        return new RateRequest(
            from: new AddressData(city: 'Riyadh', countryCode: 'SA'),
            to: new AddressData(city: 'Jeddah', countryCode: 'SA'),
            packages: [new PackageData(weightKg: 2.0)],
            currency: 'SAR',
        );
    }

    public function test_it_maps_an_aramex_rate(): void
    {
        Http::fake(['*RateCalculator*' => Http::response($this->soap(
            '<CalculateRateResponse><HasErrors>false</HasErrors><TotalAmount><Value>45.50</Value><CurrencyCode>SAR</CurrencyCode></TotalAmount></CalculateRateResponse>'
        ), 200)]);

        $rates = $this->aramex->getRates($this->account, $this->rateRequest());

        $this->assertCount(1, $rates);
        $this->assertSame('aramex', $rates[0]->carrierCode);
        $this->assertEqualsWithDelta(45.50, $rates[0]->amount, 0.01);
        $this->assertSame('SAR', $rates[0]->currency);
    }

    public function test_creating_a_shipment_returns_the_awb_and_label_url(): void
    {
        Http::fake(['*Shipping/Service*' => Http::response($this->soap(
            '<ShipmentCreationResponse><HasErrors>false</HasErrors><Shipments><ProcessedShipment><ID>4412233</ID><ShipmentLabel><LabelURL>https://labels.aramex.net/4412233.pdf</LabelURL></ShipmentLabel></ProcessedShipment></Shipments></ShipmentCreationResponse>'
        ), 200)]);

        $result = $this->aramex->createShipment($this->account, $this->draftShipment());

        $this->assertSame('4412233', $result['tracking_number']);
        $this->assertSame('https://labels.aramex.net/4412233.pdf', $result['label']['url']);
    }

    public function test_tracking_events_are_normalized(): void
    {
        Http::fake(['*Tracking*' => Http::response($this->soap(
            '<ShipmentTrackingResponse><TrackingResults>'
            .'<TrackingResult><UpdateCode>SH060</UpdateCode><UpdateDescription>Delivered</UpdateDescription><UpdateDateTime>2026-07-22T10:00:00</UpdateDateTime><UpdateLocation>Jeddah</UpdateLocation></TrackingResult>'
            .'<TrackingResult><UpdateCode>SH014</UpdateCode><UpdateDescription>Out for Delivery</UpdateDescription><UpdateDateTime>2026-07-21T08:00:00</UpdateDateTime></TrackingResult>'
            .'</TrackingResults></ShipmentTrackingResponse>'
        ), 200)]);

        $events = $this->aramex->track($this->account, '4412233');

        $this->assertSame('delivered', $events[0]->status);
        $this->assertSame('out_for_delivery', $events[1]->status);
        $this->assertSame('Jeddah', $events[0]->location);
    }

    public function test_a_soap_fault_on_validate_raises_a_carrier_auth_exception(): void
    {
        Http::fake(['*RateCalculator*' => Http::response($this->soap(
            '<soap:Fault xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><faultstring>Invalid ClientInfo</faultstring></soap:Fault>'
        ), 500)]);

        $this->expectException(\App\Services\Shipping\Exceptions\CarrierAuthException::class);
        $this->aramex->validateCredentials($this->account);
    }

    private function draftShipment(): Shipment
    {
        $store = Store::create(['organization_id' => $this->account->organization_id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $order = Order::create(['store_id' => $store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR']);
        $shipment = Shipment::create([
            'organization_id' => $this->account->organization_id, 'store_id' => $store->id, 'order_id' => $order->id,
            'reference' => 'SHP-2026-000009', 'status' => 'draft', 'currency' => 'SAR', 'total_weight_kg' => 2,
        ]);
        $shipment->packages()->create(['sequence' => 1, 'weight_kg' => 2.0]);

        return $shipment->fresh('packages');
    }
}
