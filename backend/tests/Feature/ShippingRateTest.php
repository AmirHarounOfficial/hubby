<?php

namespace Tests\Feature;

use App\Models\CarrierAccount;
use App\Models\User;
use App\Services\Shipping\Data\AddressData;
use App\Services\Shipping\Data\PackageData;
use App\Services\Shipping\Data\RateRequest;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Rate shopping: fan-out, ranking, caching, and the estimate fallback (spec 04 §4.3). */
class ShippingRateTest extends TestCase
{
    use RefreshDatabase;

    private $organization;
    private ShippingRateService $rates;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create();
        $this->organization = $this->makeOrganization($owner);
        $this->rates = app(ShippingRateService::class);
    }

    private function dhlAccount(): CarrierAccount
    {
        return CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'dhl', 'label' => 'DHL',
            'environment' => 'sandbox', 'account_number' => '1', 'credentials' => ['api_key' => 'k', 'api_secret' => 's'],
            'is_active' => true,
        ]);
    }

    private function request(): RateRequest
    {
        return new RateRequest(
            from: new AddressData(city: 'Riyadh', countryCode: 'SA'),
            to: new AddressData(city: 'Jeddah', countryCode: 'SA'),
            packages: [new PackageData(weightKg: 3.0)],
            currency: 'SAR',
        );
    }

    public function test_rates_are_ranked_cheapest_first_and_persisted(): void
    {
        Http::fake(['*/rates*' => Http::response(['products' => [
            ['productCode' => 'EXP', 'productName' => 'Express', 'totalPrice' => [['price' => 60.0, 'priceCurrency' => 'SAR']]],
            ['productCode' => 'ECO', 'productName' => 'Economy', 'totalPrice' => [['price' => 35.0, 'priceCurrency' => 'SAR']]],
        ]], 200)]);

        $result = $this->rates->shop($this->organization->id, $this->request(), [$this->dhlAccount()]);

        $this->assertSame([], $result['errors']); // surface any swallowed carrier error
        $this->assertCount(2, $result['rates']);
        $this->assertSame('ECO', $result['rates'][0]->service_code); // cheapest ranked 1
        $this->assertSame(1, $result['rates'][0]->rank);
        $this->assertEqualsWithDelta(35.0, (float) $result['rates'][0]->total_amount, 0.01);
    }

    public function test_a_second_shop_uses_the_cache_and_does_not_re_hit_the_carrier(): void
    {
        Http::fake(['*/rates*' => Http::response(['products' => [
            ['productCode' => 'EXP', 'productName' => 'Express', 'totalPrice' => [['price' => 40.0, 'priceCurrency' => 'SAR']]],
        ]], 200)]);

        $account = $this->dhlAccount();
        $this->rates->shop($this->organization->id, $this->request(), [$account]);
        $this->rates->shop($this->organization->id, $this->request(), [$account]); // served from cache

        Http::assertSentCount(1);
    }

    public function test_a_carrier_without_a_rate_api_falls_back_to_a_marked_estimate(): void
    {
        $manual = CarrierAccount::create([
            'organization_id' => $this->organization->id, 'carrier_code' => 'manual', 'label' => 'Manual',
            'environment' => 'sandbox', 'credentials' => [], 'is_active' => true,
            'settings' => ['rate_table' => ['service_code' => 'STD', 'service_name' => 'Flat', 'base' => 10, 'per_kg' => 5, 'currency' => 'SAR']],
        ]);

        $result = $this->rates->shop($this->organization->id, $this->request(), [$manual]);

        $this->assertCount(1, $result['rates']);
        $this->assertTrue((bool) $result['rates'][0]->is_estimate);
        $this->assertEqualsWithDelta(25.0, (float) $result['rates'][0]->total_amount, 0.01); // 10 + 5*3kg
    }

    public function test_a_dead_carrier_is_reported_not_fatal(): void
    {
        Http::fake(['*/rates*' => fn () => throw new \RuntimeException('boom')]);

        $result = $this->rates->shop($this->organization->id, $this->request(), [$this->dhlAccount()]);

        $this->assertCount(0, $result['rates']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame('dhl', $result['errors'][0]['carrier_code']);
    }
}
