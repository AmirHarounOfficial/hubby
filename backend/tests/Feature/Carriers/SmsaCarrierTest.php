<?php

namespace Tests\Feature\Carriers;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Carriers\SmsaCarrier;
use Database\Seeders\CarrierStatusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMSA contract test for BOTH drivers behind one class (spec 04 §6.2): the legacy SECOM SOAP surface
 * (passKey) and the newer REST surface (API key). Fixtures are representative; a live capture per
 * mode is required before enabling SMSA in production.
 */
class SmsaCarrierTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private SmsaCarrier $smsa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CarrierStatusMapSeeder::class);
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->smsa = new SmsaCarrier();
    }

    private function account(array $creds): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'smsa', 'label' => 'SMSA',
            'environment' => 'sandbox', 'credentials' => $creds, 'is_active' => true, 'cod_enabled' => true,
        ]);
    }

    private function shipment(): Shipment
    {
        $store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $order = Order::create(['store_id' => $store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR']);
        $shipment = Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $store->id, 'order_id' => $order->id,
            'reference' => 'SHP-2026-000021', 'status' => 'draft', 'currency' => 'SAR', 'total_weight_kg' => 2, 'package_count' => 1,
        ]);
        $shipment->packages()->create(['sequence' => 1, 'weight_kg' => 2]);

        return $shipment->fresh('packages');
    }

    private function soap(string $inner): string
    {
        return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
    }

    // --- SECOM SOAP driver ---

    public function test_soap_create_returns_the_awb_and_label(): void
    {
        Http::fake(['*SMSAwebService.asmx*' => function (Request $request) {
            $body = $request->body();
            if (str_contains($body, 'addShipment')) {
                return Http::response($this->soap('<addShipmentResponse xmlns="http://track.smsaexpress.com/"><addShipmentResult>SMSA7788</addShipmentResult></addShipmentResponse>'), 200);
            }

            return Http::response($this->soap('<getPDFResponse xmlns="http://track.smsaexpress.com/"><getPDFResult>'.base64_encode('%PDF label').'</getPDFResult></getPDFResponse>'), 200);
        }]);

        $result = $this->smsa->createShipment($this->account(['mode' => 'secom_soap', 'passkey' => 'PK']), $this->shipment());

        $this->assertSame('SMSA7788', $result['tracking_number']);
        $this->assertSame(base64_encode('%PDF label'), $result['label']['content_base64']);
    }

    public function test_soap_tracking_is_normalized(): void
    {
        Http::fake(['*SMSAwebService.asmx*' => Http::response($this->soap(
            '<getStatusResponse xmlns="http://track.smsaexpress.com/"><getStatusResult><Tracking>'
            .'<Item><Date>2026-07-22 10:00:00</Date><Status>Delivered</Status><Location>Riyadh</Location></Item>'
            .'<Item><Date>2026-07-21 09:00:00</Date><Status>Out for Delivery</Status><Location>Riyadh</Location></Item>'
            .'</Tracking></getStatusResult></getStatusResponse>'
        ), 200)]);

        $events = $this->smsa->track($this->account(['mode' => 'secom_soap', 'passkey' => 'PK']), 'SMSA7788');

        $this->assertSame('delivered', $events[0]->status);
        $this->assertSame('out_for_delivery', $events[1]->status);
        $this->assertSame('Riyadh', $events[0]->location);
    }

    // --- REST driver ---

    public function test_rest_create_returns_the_awb(): void
    {
        Http::fake(['*/api/shipment' => Http::response(['awbNo' => 'RST9001', 'label' => base64_encode('%PDF rest')], 200)]);

        $result = $this->smsa->createShipment(
            $this->account(['mode' => 'rest', 'api_key' => 'K', 'base_url' => 'https://smsa.test']),
            $this->shipment()
        );

        $this->assertSame('RST9001', $result['tracking_number']);
    }

    public function test_rest_bad_api_key_raises_a_carrier_auth_exception(): void
    {
        Http::fake(['*/api/track/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(\App\Services\Shipping\Exceptions\CarrierAuthException::class);
        $this->smsa->validateCredentials($this->account(['mode' => 'rest', 'api_key' => 'bad', 'base_url' => 'https://smsa.test']));
    }

    public function test_the_mode_selects_the_driver(): void
    {
        // A SOAP account hits the ASMX endpoint; a REST account hits the REST base — never crossed.
        Http::fake([
            '*SMSAwebService.asmx*' => Http::response($this->soap('<getStatusResponse xmlns="http://track.smsaexpress.com/"><getStatusResult/></getStatusResponse>'), 200),
            '*/api/track/*' => Http::response(['events' => []], 200),
        ]);

        $soapAcct = $this->account(['mode' => 'secom_soap', 'passkey' => 'PK']);
        $restAcct = CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'smsa', 'label' => 'SMSA REST',
            'environment' => 'sandbox', 'credentials' => ['mode' => 'rest', 'api_key' => 'K', 'base_url' => 'https://smsa.test'],
            'is_active' => true,
        ]);

        $this->smsa->track($soapAcct, 'X1');
        $this->smsa->track($restAcct, 'X2');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'SMSAwebService.asmx'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/track/'));
    }
}
