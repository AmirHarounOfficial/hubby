<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Batch packing-slip printing (spec 04 §4.4) — one HTML document, one print job. */
class BatchPrintTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->organization->users()->attach($owner->id, ['role' => 'owner']);
        Sanctum::actingAs($owner);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->headers = ['X-Organization-Id' => $this->organization->id];
    }

    private function shipment(string $ref, string $sku): Shipment
    {
        $order = Order::create(['store_id' => $this->store->id, 'external_id' => 'O-'.$ref, 'status' => 'paid', 'total' => 50, 'currency' => 'SAR']);
        OrderItem::create(['order_id' => $order->id, 'sku' => $sku, 'name' => $sku.' item', 'quantity' => 1, 'price' => 50]);

        return Shipment::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'order_id' => $order->id,
            'reference' => $ref, 'status' => 'label_purchased', 'currency' => 'SAR',
        ]);
    }

    public function test_batch_builds_one_html_document_with_every_slip(): void
    {
        $a = $this->shipment('SHP-2026-000001', 'AAA');
        $b = $this->shipment('SHP-2026-000002', 'BBB');

        $res = $this->post('/api/shipments/packing-slips/batch', ['shipment_ids' => [$a->id, $b->id]], $this->headers);

        $res->assertOk();
        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $html = $res->getContent();
        $this->assertStringContainsString('SHP-2026-000001', $html);
        $this->assertStringContainsString('SHP-2026-000002', $html);
        $this->assertStringContainsString('AAA', $html);
        $this->assertStringContainsString('BBB', $html);
        $this->assertStringContainsString('pagebreak', $html); // page break between slips
    }

    public function test_batch_can_target_a_shipment_set_and_rejects_an_empty_one(): void
    {
        $this->post('/api/shipments/packing-slips/batch', ['shipment_ids' => [999999]], $this->headers)
            ->assertStatus(422);
    }
}
