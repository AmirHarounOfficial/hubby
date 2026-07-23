<?php

namespace Tests\Feature\Carriers;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Carriers\FedexCarrier;
use App\Services\Shipping\Carriers\JntCarrier;
use App\Services\Shipping\Carriers\NaqelCarrier;
use App\Services\Shipping\Carriers\TorodCarrier;
use Database\Seeders\CarrierStatusMapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The breadth carriers (spec 04 §6.3–6.7): Naqel (SOAP), Torod (aggregator REST), J&T (signed REST),
 * FedEx (OAuth REST). Fixture-tested; live captures required before production per each carrier doc.
 */
class BreadthCarriersTest extends TestCase
{
    use RefreshDatabase;

    private $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CarrierStatusMapSeeder::class);
        $this->organization = $this->makeOrganization(User::factory()->create());
    }

    private function account(string $code, array $creds): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => $code, 'label' => strtoupper($code),
            'environment' => 'sandbox', 'account_number' => '99', 'credentials' => $creds, 'is_active' => true, 'cod_enabled' => true,
        ]);
    }

    private function shipment(): Shipment
    {
        $store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $order = Order::create(['store_id' => $store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR']);
        $to = OrderAddress::create(['organization_id' => $this->organization->id, 'order_id' => $order->id, 'type' => 'ship_to', 'name' => 'Sara', 'phone' => '+966500000000', 'city' => 'Riyadh', 'country_code' => 'SA']);

        return Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $store->id, 'order_id' => $order->id,
            'reference' => 'SHP-2026-000777', 'status' => 'draft', 'currency' => 'SAR', 'total_weight_kg' => 2, 'ship_to_address_id' => $to->id,
        ])->fresh();
    }

    private function soap(string $inner): string
    {
        return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'.$inner.'</soap:Body></soap:Envelope>';
    }

    public function test_naqel_soap_create_and_track(): void
    {
        Http::fake(['*GatewayWSv31*' => function (Request $req) {
            if (str_contains($req->body(), 'CreateWaybill')) {
                return Http::response($this->soap('<CreateWaybillResponse xmlns="http://tempuri.org/"><CreateWaybillResult><WaybillNo>NQL55</WaybillNo></CreateWaybillResult></CreateWaybillResponse>'), 200);
            }

            return Http::response($this->soap('<GetWaybseStatusResponse xmlns="http://tempuri.org/"><GetWaybseStatusResult><WaybillStatus><Status>Delivered</Status><Date>2026-07-22</Date><City>Riyadh</City></WaybillStatus></GetWaybseStatusResult></GetWaybseStatusResponse>'), 200);
        }]);

        $acct = $this->account('naqel', ['client_id' => 'c', 'password' => 'p']);
        $this->assertSame('NQL55', (new NaqelCarrier())->createShipment($acct, $this->shipment())['tracking_number']);
        $this->assertSame('delivered', (new NaqelCarrier())->track($acct, 'NQL55')[0]->status);
    }

    public function test_torod_returns_the_underlying_carrier(): void
    {
        Http::fake([
            '*/api/v1/shipments' => Http::response(['awb' => 'TRD9', 'courier' => 'smsa'], 200),
            '*/api/v1/shipments/*/tracking' => Http::response(['events' => [['status' => 'Out for Delivery', 'date' => '2026-07-22', 'city' => 'Jeddah']]], 200),
        ]);

        $acct = $this->account('torod', ['api_token' => 't']);
        $result = (new TorodCarrier())->createShipment($acct, $this->shipment());
        $this->assertSame('TRD9', $result['tracking_number']);
        $this->assertSame('smsa', $result['underlying_carrier']); // "SMSA via Torod"
        $this->assertSame('out_for_delivery', (new TorodCarrier())->track($acct, 'TRD9')[0]->status);
    }

    public function test_jnt_signs_the_request_and_creates(): void
    {
        Http::fake(['*/api/order/create' => Http::response(['billCode' => 'JT77'], 200)]);

        $acct = $this->account('jnt', ['api_account' => 'a', 'private_key' => 'secret', 'country_code' => 'sa']);
        $result = (new JntCarrier())->createShipment($acct, $this->shipment());

        $this->assertSame('JT77', $result['tracking_number']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('digest') && $r->header('apiAccount')[0] === 'a');
    }

    public function test_fedex_caches_its_oauth_token(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'TK', 'expires_in' => 3600], 200),
            '*/ship/v1/shipments' => Http::response(['output' => ['transactionShipments' => [['pieceResponses' => [['trackingNumber' => 'FX123']]]]]], 200),
            '*/track/v1/trackingnumbers' => Http::response(['output' => ['completeTrackResults' => [['trackResults' => [['scanEvents' => [['eventType' => 'DL', 'eventDescription' => 'Delivered', 'date' => '2026-07-22']]]]]]]], 200),
        ]);

        $acct = $this->account('fedex', ['client_id' => 'c', 'client_secret' => 's']);
        $this->assertSame('FX123', (new FedexCarrier())->createShipment($acct, $this->shipment())['tracking_number']);
        $this->assertSame('delivered', (new FedexCarrier())->track($acct, 'FX123')[0]->status);

        // Two carrier calls, one token fetch — the token is cached per account.
        Http::assertSentCount(3); // oauth once + ship + track
    }

    public function test_fedex_bad_credentials_raise_carrier_auth_exception(): void
    {
        Http::fake(['*/oauth/token' => Http::response(['errors' => 'invalid_client'], 401)]);

        $this->expectException(\App\Services\Shipping\Exceptions\CarrierAuthException::class);
        (new FedexCarrier())->validateCredentials($this->account('fedex', ['client_id' => 'x', 'client_secret' => 'y']));
    }
}
