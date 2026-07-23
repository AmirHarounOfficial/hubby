<?php

namespace Tests\Feature\Carriers;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Carriers\DhlCarrier;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\PackageData;
use App\Services\Shipping\Data\RateRequest;
use Database\Seeders\CarrierStatusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DHL Express contract test against representative MyDHL fixtures (spec 04 §6.6/§6.8). Proves the
 * abstraction handles a real REST carrier: rates → CarrierRate, shipment → label bytes, tracking →
 * normalized events. Fixtures are representative; a live sandbox capture is required before enabling
 * DHL in production.
 */
class DhlCarrierTest extends TestCase
{
    use RefreshDatabase;

    private CarrierAccount $account;
    private DhlCarrier $dhl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CarrierStatusMapSeeder::class);
        $owner = User::factory()->create();
        $org = $this->makeOrganization($owner);
        $this->account = CarrierAccount::create([
            'organization_id' => $org->id, 'carrier_code' => 'dhl', 'label' => 'DHL',
            'environment' => 'sandbox', 'account_number' => '9999',
            'credentials' => ['api_key' => 'k', 'api_secret' => 's', 'account_number' => '9999'],
        ]);
        $this->dhl = new DhlCarrier();
    }

    private function rateRequest(): RateRequest
    {
        return new RateRequest(
            from: new AddressData(city: 'Riyadh', countryCode: 'SA'),
            to: new AddressData(city: 'Jeddah', countryCode: 'SA'),
            packages: [new PackageData(weightKg: 2.0, lengthCm: 20, widthCm: 15, heightCm: 10)],
            currency: 'SAR',
        );
    }

    public function test_it_maps_dhl_products_to_carrier_rates(): void
    {
        Http::fake(['*/rates*' => Http::response(['products' => [
            ['productCode' => 'P', 'productName' => 'EXPRESS WORLDWIDE',
             'totalPrice' => [['price' => 45.0, 'priceCurrency' => 'SAR']],
             'deliveryCapabilities' => ['totalTransitDays' => 2]],
        ]], 200)]);

        $rates = $this->dhl->getRates($this->account, $this->rateRequest());

        $this->assertCount(1, $rates);
        $this->assertSame('dhl', $rates[0]->carrierCode);
        $this->assertSame(45.0, $rates[0]->amount);
        $this->assertSame(2, $rates[0]->transitDaysMax);
    }

    public function test_creating_a_shipment_returns_the_awb_and_label_bytes(): void
    {
        $labelB64 = base64_encode('%PDF-1.4 fake label');
        Http::fake(['*/shipments' => Http::response([
            'shipmentTrackingNumber' => '1234567890',
            'packages' => [['trackingNumber' => '1234567890']],
            'documents' => [['typeCode' => 'label', 'imageFormat' => 'PDF', 'content' => $labelB64]],
        ], 201)]);

        $shipment = $this->draftShipment();
        $result = $this->dhl->createShipment($this->account, $shipment);

        $this->assertSame('1234567890', $result['tracking_number']);
        $this->assertSame($labelB64, $result['label']['content_base64']);
        $this->assertSame('pdf', $result['label']['format']);
    }

    public function test_tracking_events_are_normalized_via_the_status_map(): void
    {
        Http::fake(['*/tracking*' => Http::response(['shipments' => [[
            'events' => [
                ['date' => '2026-07-22', 'time' => '09:00:00', 'typeCode' => 'OK', 'description' => 'Delivered',
                 'serviceArea' => [['description' => 'Jeddah']]],
                ['date' => '2026-07-21', 'time' => '18:00:00', 'typeCode' => 'WC', 'description' => 'With delivery courier'],
            ],
        ]]], 200)]);

        $events = $this->dhl->track($this->account, '1234567890');

        $this->assertSame('delivered', $events[0]->status);
        $this->assertSame('out_for_delivery', $events[1]->status);
        $this->assertSame('Jeddah', $events[0]->location);
    }

    public function test_bad_credentials_raise_a_carrier_auth_exception(): void
    {
        Http::fake(['*/products*' => Http::response(['detail' => 'unauthorized'], 401)]);

        $this->expectException(\App\Services\Shipping\Exceptions\CarrierAuthException::class);
        $this->dhl->validateCredentials($this->account);
    }

    private function draftShipment(): Shipment
    {
        $store = Store::create(['organization_id' => $this->account->organization_id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $order = Order::create(['store_id' => $store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR']);
        $shipment = Shipment::create([
            'organization_id' => $this->account->organization_id, 'store_id' => $store->id, 'order_id' => $order->id,
            'reference' => 'SHP-2026-000001', 'status' => 'draft', 'currency' => 'SAR',
        ]);
        $shipment->packages()->create(['sequence' => 1, 'weight_kg' => 2.0]);

        return $shipment->fresh('packages');
    }
}
