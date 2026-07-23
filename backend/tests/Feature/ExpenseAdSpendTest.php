<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\OrderProfit;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\ExpenseAmortizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closes spec 01's expenses + ad_spend gap: overhead is amortized to daily slices and, with
 * advertising, subtracted from the period P&L so net profit reflects the whole business.
 */
class ExpenseAdSpendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private $organization;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->makeOrganization($this->user);
        $this->user->organizations()->attach($this->organization->id, ['role' => 'owner']);
        $this->store = Store::create([
            'organization_id' => $this->organization->id,
            'name' => 'Store',
            'platform' => 'salla',
            'status' => 'connected',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function headers(): array
    {
        return ['X-Organization-Id' => $this->organization->id];
    }

    public function test_amortizer_spreads_a_recurring_monthly_expense_to_a_daily_rate(): void
    {
        Expense::create([
            'organization_id' => $this->organization->id,
            'name' => 'SaaS tools',
            'category' => 'software',
            'type' => 'recurring',
            'recurrence' => 'monthly',
            'amount' => 3000,
            'amount_base' => 3000,
            'starts_on' => '2026-06-01',
            'amortize' => true,
        ]);

        app(ExpenseAmortizer::class)->amortize($this->organization, '2026-06-01', '2026-06-30');

        // Monthly 3000 → 100/day (3000 / 30-day period). 30 slices in June.
        $this->assertSame(30, ExpenseAllocation::where('organization_id', $this->organization->id)->count());
        $june = ExpenseAllocation::whereBetween('date', ['2026-06-01', '2026-06-30'])->sum('amount_base');
        $this->assertEqualsWithDelta(3000.0, (float) $june, 0.01);
    }

    public function test_amortizer_is_idempotent(): void
    {
        Expense::create([
            'organization_id' => $this->organization->id,
            'name' => 'Rent', 'category' => 'rent', 'type' => 'recurring', 'recurrence' => 'monthly',
            'amount' => 6000, 'amount_base' => 6000, 'starts_on' => '2026-06-01', 'amortize' => true,
        ]);
        $amortizer = app(ExpenseAmortizer::class);

        $amortizer->amortize($this->organization, '2026-06-01', '2026-06-30');
        $amortizer->amortize($this->organization, '2026-06-01', '2026-06-30');
        $amortizer->amortize($this->organization, '2026-06-01', '2026-06-30');

        // Rebuilt each run, never accumulated.
        $this->assertSame(30, ExpenseAllocation::count());
    }

    public function test_one_off_non_amortized_expense_lands_on_a_single_day(): void
    {
        Expense::create([
            'organization_id' => $this->organization->id,
            'name' => 'Trade licence', 'category' => 'tax', 'type' => 'one_off',
            'amount' => 1200, 'amount_base' => 1200, 'starts_on' => '2026-06-10', 'amortize' => false,
        ]);

        app(ExpenseAmortizer::class)->amortize($this->organization, '2026-06-01', '2026-06-30');

        $this->assertSame(1, ExpenseAllocation::count());
        $this->assertEquals(1200.0, (float) ExpenseAllocation::first()->amount_base);
        $this->assertSame('2026-06-10', ExpenseAllocation::first()->date->toDateString());
    }

    public function test_creating_an_expense_via_the_api_amortizes_it_immediately(): void
    {
        $this->postJson('/api/expenses', [
            'name' => 'Warehouse rent', 'category' => 'rent', 'type' => 'recurring',
            'recurrence' => 'monthly', 'amount' => 9000, 'starts_on' => now()->startOfMonth()->toDateString(),
        ], $this->headers())->assertStatus(201);

        $this->assertGreaterThan(0, ExpenseAllocation::where('organization_id', $this->organization->id)->count());
    }

    public function test_ad_spend_entry_is_idempotent_on_its_key(): void
    {
        $payload = [
            'channel' => 'meta', 'campaign_external_id' => 'cmp-1', 'date' => '2026-06-15', 'spend' => 500,
        ];
        $this->postJson('/api/ad-spend', $payload, $this->headers())->assertStatus(201);
        $this->postJson('/api/ad-spend', array_merge($payload, ['spend' => 750]), $this->headers())->assertStatus(201);

        $this->assertSame(1, AdSpend::where('organization_id', $this->organization->id)->count());
        $this->assertEquals(750.0, (float) AdSpend::first()->spend_base); // updated, not duplicated
    }

    public function test_advertising_and_expenses_are_subtracted_from_the_period_pl(): void
    {
        // An order with 1000 net revenue and 600 net profit before overhead.
        OrderProfit::create([
            'organization_id' => $this->organization->id,
            'order_id' => \App\Models\Order::create([
                'store_id' => $this->store->id, 'external_id' => 'O-1', 'status' => 'paid',
                'total' => 1000, 'currency' => 'SAR',
            ])->id,
            'store_id' => $this->store->id,
            'placed_on' => '2026-06-15',
            'base_currency' => 'SAR',
            'gross_revenue_base' => '1000.0000', 'net_revenue_base' => '1000.0000',
            'cogs_base' => '300.0000', 'total_fees_base' => '100.0000', 'net_profit_base' => '600.0000',
            'is_estimated' => false, 'missing_cost' => false, 'computed_at' => now(),
        ]);

        // 200 advertising + a 300/day-for-1-day one-off expense = 500 overhead.
        $this->postJson('/api/ad-spend',
            ['channel' => 'meta', 'date' => '2026-06-15', 'spend' => 200], $this->headers())->assertStatus(201);
        $this->postJson('/api/expenses', [
            'name' => 'Photoshoot', 'category' => 'marketing', 'type' => 'one_off',
            'amount' => 300, 'starts_on' => '2026-06-15', 'amortize' => false,
        ], $this->headers())->assertStatus(201);

        $res = $this->getJson('/api/analytics/profit?start_date=2026-06-01&end_date=2026-06-30', $this->headers());

        $res->assertOk()
            ->assertJsonPath('operating_profit', '600.0000')
            ->assertJsonPath('ad_spend', '200.0000')
            ->assertJsonPath('expenses', '300.0000')
            // 600 − 200 − 300 = 100
            ->assertJsonPath('net_profit', '100.0000');
    }
}
