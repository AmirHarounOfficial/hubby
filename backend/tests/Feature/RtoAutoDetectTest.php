<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\TrackingIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** RTO auto-detection: a carrier "returning to origin" scan raises an RTO return (spec 03 §4.2). */
class RtoAutoDetectTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private Order $order;
    private Shipment $shipment;
    private TrackingIngestService $ingest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->makeOrganization(User::factory()->create());
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->order = Order::create(['store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR', 'is_cod' => true, 'cod_amount' => 100]);
        OrderItem::create(['order_id' => $this->order->id, 'sku' => 'W', 'name' => 'Widget', 'quantity' => 2, 'price' => 50]);
        $this->shipment = Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'order_id' => $this->order->id,
            'reference' => 'SHP-2026-000001', 'status' => 'in_transit', 'direction' => 'outbound', 'carrier_code' => 'aramex',
            'tracking_number' => 'AWB1', 'currency' => 'SAR',
        ]);
        $this->ingest = app(TrackingIngestService::class);
    }

    private function rtoEvent(string $at): CarrierTrackingEvent
    {
        return new CarrierTrackingEvent(status: 'returned_to_origin', eventAt: Carbon::parse($at), rawCode: 'RTO', isException: true);
    }

    public function test_a_returned_to_origin_scan_raises_an_rto_return(): void
    {
        $this->ingest->ingest($this->shipment, [$this->rtoEvent('2026-07-22 10:00:00')]);

        $rto = ReturnRequest::where('order_id', $this->order->id)->where('type', 'rto')->first();
        $this->assertNotNull($rto);
        $this->assertSame('in_transit', $rto->status);
        $this->assertSame('carrier', $rto->origin);
        $this->assertSame($this->shipment->id, $rto->outbound_shipment_id);
        $this->assertSame(2, (int) $rto->items->sum('quantity_requested'));

        // The order rolls up to RTO via the shipment observer.
        $this->assertSame('rto', $this->order->fresh()->fulfillment_status);
    }

    public function test_rto_detection_is_idempotent(): void
    {
        $this->ingest->ingest($this->shipment, [$this->rtoEvent('2026-07-22 10:00:00')]);
        // A later duplicate/again RTO scan must not create a second RTO return.
        $this->ingest->ingest($this->shipment->fresh(), [$this->rtoEvent('2026-07-23 08:00:00')]);

        $this->assertSame(1, ReturnRequest::where('order_id', $this->order->id)->where('type', 'rto')->count());
    }

    public function test_no_rto_for_a_normal_delivery(): void
    {
        $this->ingest->ingest($this->shipment, [new CarrierTrackingEvent(status: 'delivered', eventAt: Carbon::parse('2026-07-22 10:00:00'))]);

        $this->assertSame(0, ReturnRequest::where('order_id', $this->order->id)->where('type', 'rto')->count());
    }
}
