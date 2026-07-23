<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\ShippingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * COD is a collection instruction, not a flag (spec 04 §4.7). These guards are the part Western tools
 * get wrong — dropping the instruction loses the merchant the whole order value.
 */
class CodTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;
    private ShippingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'salla', 'status' => 'connected']);
        $this->service = app(ShippingService::class);
    }

    private function codOrder(float $total = 100, float $cod = 100): Order
    {
        $order = Order::create([
            'store_id' => $this->store->id, 'external_id' => 'O-'.uniqid(), 'status' => 'paid',
            'total' => $total, 'currency' => 'SAR', 'is_cod' => true, 'cod_amount' => $cod,
        ]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'W', 'name' => 'W', 'quantity' => 1, 'price' => $total]);

        return $order->fresh('items');
    }

    private function account(bool $codEnabled): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual',
            'environment' => 'sandbox', 'credentials' => [], 'is_active' => true, 'cod_enabled' => $codEnabled,
        ]);
    }

    private function expectCode(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected {$code}");
        } catch (\RuntimeException $e) {
            $this->assertSame($code, $e->getMessage());
        }
    }

    public function test_a_cod_shipment_needs_a_cod_enabled_account(): void
    {
        $shipment = $this->service->createDraft($this->codOrder(), ['weight_kg' => 1]);
        $this->expectCode('COD_NOT_ENABLED_ON_ACCOUNT', fn () => $this->service->purchaseLabel(
            $shipment->fresh(['packages', 'items']), $this->account(false)
        ));
    }

    public function test_cod_currency_must_match_the_order(): void
    {
        $shipment = $this->service->createDraft($this->codOrder(), ['weight_kg' => 1]);
        $shipment->update(['cod_currency' => 'USD']); // order is SAR

        $this->expectCode('COD_CURRENCY_MISMATCH', fn () => $this->service->purchaseLabel(
            $shipment->fresh(['packages', 'items']), $this->account(true)
        ));
    }

    public function test_total_cod_across_shipments_cannot_exceed_the_order(): void
    {
        $order = $this->codOrder(total: 100, cod: 100);
        $account = $this->account(true);

        // First COD shipment for the whole 100 succeeds.
        $first = $this->service->createDraft($order, ['weight_kg' => 1]);
        $this->service->purchaseLabel($first->fresh(['packages', 'items']), $account);

        // A second COD shipment (another 100) would instruct collecting 200 on a 100 order.
        $second = $this->service->createDraft($order->fresh('items'), ['weight_kg' => 1]);
        $this->expectCode('COD_EXCEEDS_ORDER_TOTAL', fn () => $this->service->purchaseLabel(
            $second->fresh(['packages', 'items']), $account
        ));
    }

    public function test_a_valid_cod_shipment_is_accepted(): void
    {
        $shipment = $this->service->createDraft($this->codOrder(), ['weight_kg' => 1]);
        $shipment = $this->service->purchaseLabel($shipment->fresh(['packages', 'items']), $this->account(true));

        $this->assertSame('label_purchased', $shipment->status);
    }
}
