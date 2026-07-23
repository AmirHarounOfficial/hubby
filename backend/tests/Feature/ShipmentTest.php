<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The shipment lifecycle + order fulfilment rollup (spec 04 §4.1) via the manual carrier. */
class ShipmentTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private ShippingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->organization = $this->makeOrganization($user);
        $this->store = Store::create([
            'organization_id' => $this->organization->id, 'name' => 'Salla', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->service = app(ShippingService::class);
    }

    private function order(int $qty = 2): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-'.uniqid(), 'status' => 'paid',
            'total' => 200, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W-1', 'name' => 'Widget', 'quantity' => $qty, 'price' => 100]);

        return $order->fresh('items');
    }

    private function manualAccount(): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual',
            'environment' => 'sandbox', 'credentials' => [], 'is_active' => true,
        ]);
    }

    public function test_a_draft_shipment_leaves_the_order_unfulfilled(): void
    {
        $order = $this->order();
        $shipment = $this->service->createDraft($order, ['weight_kg' => 2]);

        $this->assertSame('draft', $shipment->status);
        $this->assertMatchesRegularExpression('/^SHP-\d{4}-\d{6}$/', $shipment->reference);
        $this->assertSame(1, $shipment->packages->count());
        $this->assertSame(2, (int) $shipment->items->sum('quantity'));

        // A draft is not fulfilment.
        $this->assertSame('unfulfilled', $order->fresh()->fulfillment_status);
        $this->assertSame(0, (int) $order->fresh()->shipments_count);
    }

    public function test_purchasing_a_manual_label_moves_the_order_to_shipped(): void
    {
        $order = $this->order();
        $shipment = $this->service->createDraft($order, ['weight_kg' => 2, 'tracking_number' => 'MANUAL-999']);
        $shipment = $this->service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->manualAccount());

        $this->assertSame('label_purchased', $shipment->status);
        $this->assertSame('manual', $shipment->carrier_code);
        $this->assertSame('MANUAL-999', $shipment->tracking_number);

        $this->assertSame('shipped', $order->fresh()->fulfillment_status);
        $this->assertSame(1, (int) $order->fresh()->shipments_count);
    }

    public function test_a_cod_shipment_without_an_amount_is_rejected(): void
    {
        $order = $this->order();
        $order->update(['is_cod' => true, 'cod_amount' => 0]);
        $shipment = $this->service->createDraft($order->fresh('items'), ['weight_kg' => 2]);

        $this->expectException(\RuntimeException::class);
        $this->service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->manualAccount());
    }

    public function test_cancelling_a_label_purchased_shipment_unwinds_the_rollup(): void
    {
        $order = $this->order();
        $shipment = $this->service->createDraft($order, ['weight_kg' => 2, 'tracking_number' => 'M-1']);
        $shipment = $this->service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->manualAccount());

        $this->service->cancel($shipment->fresh());

        $this->assertSame('cancelled', $shipment->fresh()->status);
        $this->assertSame('unfulfilled', $order->fresh()->fulfillment_status);
    }
}
