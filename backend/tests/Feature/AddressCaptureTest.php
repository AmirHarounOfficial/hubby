<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\Store;
use App\Models\User;
use App\Services\Shipping\AddressExtractor;
use Database\Seeders\CityAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ship-to address capture from a synced platform order (spec 04 §4.8 dependency). */
class AddressCaptureTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CityAliasSeeder::class);
        $this->organization = $this->makeOrganization(User::factory()->create());
        $this->store = Store::create(['organization_id' => $this->organization->id, 'name' => 'S', 'platform' => 'shopify', 'status' => 'connected']);
    }

    private function order(): Order
    {
        return Order::create(['store_id' => $this->store->id, 'external_id' => 'O', 'status' => 'paid', 'total' => 100, 'currency' => 'SAR'])->load('store');
    }

    public function test_a_shopify_shipping_address_is_captured_and_normalized(): void
    {
        $raw = ['shipping_address' => [
            'first_name' => 'Sara', 'last_name' => 'A', 'phone' => '0551234567',
            'address1' => 'King Fahd Rd', 'city' => 'Ar Riyadh', 'province' => 'Riyadh', 'zip' => '12345', 'country_code' => 'SA',
        ]];

        $addr = app(AddressExtractor::class)->forOrder($this->order(), 'shopify', $raw);

        $this->assertNotNull($addr);
        $this->assertSame('ship_to', $addr->type);
        $this->assertSame('Sara A', $addr->name);
        $this->assertSame('+966551234567', $addr->phone);      // E.164
        $this->assertSame('riyadh', $addr->city_normalized);   // "Ar Riyadh" → canonical
    }

    public function test_re_syncing_updates_the_same_address_row(): void
    {
        $order = $this->order();
        $raw = ['shipping_address' => ['name' => 'Sara', 'phone' => '0551234567', 'address1' => 'A', 'city' => 'Jeddah', 'country_code' => 'SA']];

        app(AddressExtractor::class)->forOrder($order, 'shopify', $raw);
        $raw['shipping_address']['address1'] = 'B';
        app(AddressExtractor::class)->forOrder($order, 'shopify', $raw);

        $this->assertSame(1, OrderAddress::where('order_id', $order->id)->count());
        $this->assertSame('B', OrderAddress::where('order_id', $order->id)->first()->line1);
    }

    public function test_an_order_with_no_address_is_skipped(): void
    {
        $this->assertNull(app(AddressExtractor::class)->forOrder($this->order(), 'shopify', ['id' => 1]));
    }
}
