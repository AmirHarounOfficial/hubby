<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Label purchase via DHL: the label is downloaded, stored on our disk, and streamable (spec §4.4). */
class LabelPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
    }

    private function order(): Order
    {
        $order = Order::create(['store_id' => $this->store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W', 'name' => 'W', 'quantity' => 1, 'price' => 100]);

        return $order->fresh('items');
    }

    private function dhlAccount(): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'dhl', 'label' => 'DHL',
            'environment' => 'sandbox', 'account_number' => '1', 'credentials' => ['api_key' => 'k', 'api_secret' => 's'], 'is_active' => true,
        ]);
    }

    private function fakeDhlLabel(): string
    {
        $b64 = base64_encode('%PDF-1.4 the label bytes');
        Http::fake(['*/shipments' => Http::response([
            'shipmentTrackingNumber' => 'JD0002',
            'packages' => [['trackingNumber' => 'JD0002']],
            'documents' => [['typeCode' => 'label', 'imageFormat' => 'PDF', 'content' => $b64]],
        ], 201)]);

        return $b64;
    }

    public function test_purchasing_a_dhl_label_stores_our_own_copy(): void
    {
        $this->fakeDhlLabel();
        $service = app(ShippingService::class);
        $shipment = $service->createDraft($this->order(), ['weight_kg' => 2]);

        $shipment = $service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->dhlAccount());

        $this->assertSame('label_purchased', $shipment->status);
        $this->assertSame('JD0002', $shipment->tracking_number);

        $label = ShippingLabel::where('shipment_id', $shipment->id)->first();
        $this->assertNotNull($label);
        $this->assertSame('pdf', $label->format);
        $this->assertNotNull($label->checksum);
        Storage::disk('local')->assertExists($label->path);
    }

    public function test_buying_a_label_twice_never_buys_two(): void
    {
        $this->fakeDhlLabel();
        $service = app(ShippingService::class);
        $shipment = $service->createDraft($this->order(), ['weight_kg' => 2]);
        $account = $this->dhlAccount();

        $service->purchaseLabel($shipment->fresh(['packages', 'items']), $account);
        $service->purchaseLabel($shipment->fresh(['packages', 'items']), $account); // idempotent

        $this->assertSame(1, ShippingLabel::where('shipment_id', $shipment->id)->count());
    }

    public function test_the_stored_label_streams_back_as_pdf(): void
    {
        $this->fakeDhlLabel();
        Sanctum::actingAs($this->owner);
        $service = app(ShippingService::class);
        $shipment = $service->createDraft($this->order(), ['weight_kg' => 2]);
        $service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->dhlAccount());

        $res = $this->get("/api/shipments/{$shipment->id}/label", ['X-Organization-Id' => $this->organization->id]);

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
    }
}
