<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\ShippingLabel;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Barcode\Code128;
use App\Services\Shipping\PackingSlipRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Packing slips (spec 04 §4.9): merchant-generated, bilingual, barcoded, no prices. */
class PackingSlipTest extends TestCase
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
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'Nour Store', 'platform' => 'salla', 'status' => 'connected',
        ]);
    }

    private function codShipment(): Shipment
    {
        $order = Order::create(['store_id' => $this->store->id, 'external_id' => 'ORD-501', 'status' => 'paid', 'total' => 349, 'currency' => 'SAR', 'is_cod' => true, 'cod_amount' => 349]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1', 'name' => 'Abaya', 'quantity' => 2, 'price' => 150]);
        $to = OrderAddress::create([
            'organization_id' => $this->organization->id, 'order_id' => $order->id, 'type' => 'ship_to',
            'name' => 'Sara', 'phone' => '+966551234567', 'city' => 'Riyadh', 'district' => 'Al Olaya', 'country_code' => 'SA',
        ]);

        return Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'order_id' => $order->id,
            'reference' => 'SHP-2026-000501', 'status' => 'label_purchased', 'carrier_code' => 'aramex', 'tracking_number' => '44112233',
            'ship_to_address_id' => $to->id, 'is_cod' => true, 'cod_amount' => 349, 'cod_currency' => 'SAR', 'currency' => 'SAR', 'package_count' => 1,
        ])->fresh();
    }

    public function test_the_barcode_encodes_as_scannable_svg(): void
    {
        $svg = (new Code128())->svg('SHP-2026-000501');
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
    }

    public function test_rendering_stores_an_html_slip_with_the_order_details(): void
    {
        $label = app(PackingSlipRenderer::class)->render($this->codShipment());

        $this->assertSame('packing_slip', $label->type);
        $this->assertSame('html', $label->format);
        Storage::disk('local')->assertExists($label->path);

        $html = Storage::disk('local')->get($label->path);
        $this->assertStringContainsString('Nour Store', $html);
        $this->assertStringContainsString('SHP-2026-000501', $html); // reference + barcode caption
        $this->assertStringContainsString('Abaya', $html);
        $this->assertStringContainsString('Sara', $html);
        $this->assertStringContainsString('349.00 SAR', $html); // COD box
        $this->assertStringContainsString('<svg', $html); // barcode
        // No prices on the slip (many merchants ship gifts).
        $this->assertStringNotContainsString('150.00', $html);
    }

    public function test_the_endpoint_streams_the_slip_as_html(): void
    {
        Sanctum::actingAs($this->owner);
        $shipment = $this->codShipment();

        $res = $this->get("/api/shipments/{$shipment->id}/packing-slip", ['X-Organization-Id' => $this->organization->id]);

        $res->assertOk();
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        // Re-serving reuses the stored artefact rather than piling up duplicates.
        $this->get("/api/shipments/{$shipment->id}/packing-slip", ['X-Organization-Id' => $this->organization->id])->assertOk();
        $this->assertSame(1, ShippingLabel::where('shipment_id', $shipment->id)->where('type', 'packing_slip')->count());
    }
}
