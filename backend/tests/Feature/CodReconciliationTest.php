<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\CodTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\ShippingService;
use App\Services\Shipping\TrackingIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** COD ledger + cash-in-transit reconciliation (spec 06 §4.1/§4.5). */
class CodReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private CarrierAccount $account;
    private User $owner;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->organization = $this->makeOrganization($this->owner);
        $this->organization->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->account = CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual',
            'environment' => 'sandbox', 'credentials' => [], 'is_active' => true, 'cod_enabled' => true,
        ]);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function codShipment(float $cod = 200): Shipment
    {
        $order = Order::create(['store_id' => $this->store->id, 'external_id' => 'O-'.uniqid(), 'status' => 'paid', 'total' => $cod, 'currency' => 'SAR', 'is_cod' => true, 'cod_amount' => $cod]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W', 'name' => 'W', 'quantity' => 1, 'price' => $cod]);
        $service = app(ShippingService::class);
        $shipment = $service->createDraft($order->fresh('items'), ['weight_kg' => 1, 'tracking_number' => 'AWB-'.uniqid()]);

        return $service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->account);
    }

    public function test_dispatch_then_delivery_moves_cod_cash_through_the_ledger(): void
    {
        $shipment = $this->codShipment(200);

        // Label bought → the ledger opens as in_transit (carrier will collect).
        $txn = CodTransaction::where('order_id', $shipment->order_id)->first();
        $this->assertSame('in_transit', $txn->status);
        $this->assertEqualsWithDelta(200.0, (float) $txn->expected_amount, 0.01);

        // Delivered → collected (cash in the carrier's hands, due date set).
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'delivered', eventAt: Carbon::parse('2026-07-20 10:00:00'))]);
        $txn->refresh();
        $this->assertSame('collected', $txn->status);
        $this->assertEqualsWithDelta(200.0, (float) $txn->collected_amount, 0.01);
        $this->assertNotNull($txn->due_at);
    }

    public function test_the_summary_reports_cash_awaiting_remittance(): void
    {
        Sanctum::actingAs($this->owner);
        $shipment = $this->codShipment(150);
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'delivered', eventAt: now()->subDays(3))]);

        $this->getJson('/api/cod/summary', $this->headers)
            ->assertOk()
            ->assertJsonPath('awaiting_remittance', 150);
    }

    public function test_marking_remitted_closes_the_cash_loop(): void
    {
        Sanctum::actingAs($this->owner);
        $shipment = $this->codShipment(300);
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'delivered', eventAt: now())]);
        $txn = CodTransaction::where('order_id', $shipment->order_id)->first();

        $this->postJson("/api/cod/transactions/{$txn->id}/remitted", ['amount' => 300], $this->headers)
            ->assertOk()->assertJsonPath('status', 'remitted');

        // No longer awaiting remittance.
        $this->getJson('/api/cod/summary', $this->headers)->assertJsonPath('awaiting_remittance', 0);
    }

    public function test_rto_is_booked_as_lost_cod(): void
    {
        $shipment = $this->codShipment(120);
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'returned_to_origin', eventAt: now(), isException: true)]);

        $txn = CodTransaction::where('order_id', $shipment->order_id)->first();
        $this->assertSame('rto', $txn->status);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/cod/summary', $this->headers)->assertJsonPath('rto_amount', 120);
    }

    public function test_an_illegal_transition_throws(): void
    {
        $service = app(\App\Services\Cod\CodTransactionService::class);
        $this->assertFalse($service->canTransition('rto', 'collected'));
        $this->assertFalse($service->canTransition('reconciled', 'remitted'));

        // An RTO parcel never collected cash — trying to remit it is illegal (rto → collected).
        $shipment = $this->codShipment(100);
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'returned_to_origin', eventAt: now(), isException: true)]);
        $txn = CodTransaction::where('order_id', $shipment->order_id)->first();

        $this->expectException(\App\Exceptions\InvalidCodTransition::class);
        $service->markRemitted($txn);
    }

    public function test_a_viewer_cannot_mark_remittance(): void
    {
        $shipment = $this->codShipment(100);
        app(TrackingIngestService::class)->ingest($shipment, [new CarrierTrackingEvent(status: 'delivered', eventAt: now())]);
        $txn = CodTransaction::where('order_id', $shipment->order_id)->first();

        $viewer = User::factory()->create();
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        Sanctum::actingAs($viewer);

        $this->postJson("/api/cod/transactions/{$txn->id}/remitted", [], $this->headers)->assertForbidden();
    }
}
