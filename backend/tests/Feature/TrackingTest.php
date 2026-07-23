<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\TrackingIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Out-of-order-safe tracking ingest + fulfilment rollup (spec 04 §4.2). */
class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private TrackingIngestService $ingest;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $org = $this->makeOrganization($user);
        $store = Store::create([
            'organization_id' => $org->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected',
        ]);
        $this->order = Order::create([
            'store_id' => $store->id, 'external_id' => 'O-1', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR',
        ]);
        OrderItem::create(['order_id' => $this->order->id, 'sku' => 'W', 'name' => 'W', 'quantity' => 1, 'price' => 100]);

        $this->shipment = Shipment::create([
            'organization_id' => $org->id, 'store_id' => $store->id, 'order_id' => $this->order->id,
            'reference' => 'SHP-2026-000001', 'status' => 'label_purchased', 'carrier_code' => 'manual',
        ]);
        $this->ingest = app(TrackingIngestService::class);
    }

    private Shipment $shipment;

    private function event(string $status, string $at, bool $exception = false): CarrierTrackingEvent
    {
        return new CarrierTrackingEvent(
            status: $status,
            eventAt: Carbon::parse($at),
            rawCode: $status,
            isException: $exception,
        );
    }

    public function test_duplicate_events_are_deduped_by_fingerprint(): void
    {
        $e = $this->event('in_transit', '2026-07-20 10:00:00');
        $this->ingest->ingest($this->shipment, [$e]);
        $this->ingest->ingest($this->shipment->fresh(), [$e]); // same event redelivered

        $this->assertSame(1, TrackingEvent::where('shipment_id', $this->shipment->id)->count());
    }

    public function test_status_follows_the_greatest_event_at_not_arrival_order(): void
    {
        // Delivered arrives first, then a stale in-transit scan with an EARLIER timestamp backfills.
        $this->ingest->ingest($this->shipment, [$this->event('delivered', '2026-07-22 15:00:00')]);
        $this->ingest->ingest($this->shipment->fresh(), [$this->event('in_transit', '2026-07-21 09:00:00')]);

        $fresh = $this->shipment->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
        // The order rolls up to delivered.
        $this->assertSame('delivered', $this->order->fresh()->fulfillment_status);
    }

    public function test_a_strictly_later_rto_event_moves_a_delivered_shipment(): void
    {
        $this->ingest->ingest($this->shipment, [$this->event('delivered', '2026-07-22 15:00:00')]);
        // Customer refuses at the door after a mis-scan — genuine delivered → returned_to_origin.
        $this->ingest->ingest($this->shipment->fresh(), [$this->event('returned_to_origin', '2026-07-23 11:00:00', true)]);

        $this->assertSame('returned_to_origin', $this->shipment->fresh()->status);
        // Which the order surfaces as an RTO.
        $this->assertSame('rto', $this->order->fresh()->fulfillment_status);
    }

    public function test_shipped_at_is_stamped_from_the_event_not_now(): void
    {
        $this->ingest->ingest($this->shipment, [$this->event('picked_up', '2026-07-20 08:00:00')]);

        $this->assertSame('2026-07-20 08:00:00', $this->shipment->fresh()->shipped_at->format('Y-m-d H:i:s'));
    }
}
