<?php

namespace Database\Seeders;

use App\Models\AdSpend;
use App\Models\CostLayer;
use App\Models\Expense;
use App\Models\FeeRule;
use App\Models\Order;
use App\Models\OrderFee;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Profit\FeeEstimator;
use App\Services\Profit\OrderProfitCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Populates the Profit & Loss dashboard with a believable 90-day history so the /profit screen,
 * the reporting API, and the coverage banner all have something real to show on a fresh deploy.
 *
 * It seeds the whole chain the profit engine reads: product costs + FIFO cost layers, a couple of
 * platform fee rules, and orders whose placed_at is spread across 90 days (while created_at is now —
 * deliberately, to exercise defect-#7's order-date bucketing), then runs the real FeeEstimator +
 * OrderProfitCalculator per order to materialise the rollups.
 *
 * Runs once: it no-ops if its demo orders (external_id `PFD-*`) already exist, so it's safe to leave
 * in the deploy pipeline without duplicating or churning data. One SKU is intentionally left with no
 * cost so the dashboard's "how complete is this?" banner has something to report.
 */
class ProfitDemoSeeder extends Seeder
{
    /** sku => [name, price (VAT-incl SAR), unit cost SAR|null] */
    private const CATALOGUE = [
        'OUD-ROYAL-50'   => ['Royal Oud 50ml',        459.00, 172.00],
        'ABAYA-CLASSIC'  => ['Classic Black Abaya',   340.00, 188.00],
        'DATES-AJWA-1KG' => ['Ajwa Dates 1kg',         95.00,  44.00],
        'ATTAR-MUSK-12'  => ['Musk Attar 12ml',       120.00,  38.00],
        'PRAYER-RUG-LUX' => ['Luxury Prayer Rug',     210.00, 205.00], // razor-thin margin on purpose
        'HONEY-SIDR-500' => ['Sidr Honey 500g',       260.00,  null], // no cost on file → coverage gap
    ];

    private const COMMITTED = ['paid', 'processing', 'shipped', 'delivered', 'completed'];

    public function run(): void
    {
        // Seed exactly once. If the demo history is already present we do nothing — re-running
        // would duplicate/churn the data (and, being randomised, change every number on the
        // dashboard). Safe to leave in the deploy pipeline: after the first run it's a no-op.
        if (Order::where('external_id', 'like', 'PFD-%')->exists()) {
            $this->command?->info('ProfitDemoSeeder: demo data already present — skipping.');

            return;
        }

        $org = $this->organization();
        $baseCurrency = $org->base_currency ?? 'SAR';
        [$salla, $shopify] = $this->stores($org);

        $this->seedCatalogue($org);
        $this->seedFeeRules($org);

        $feeEstimator = app(FeeEstimator::class);
        $calculator = app(OrderProfitCalculator::class);

        $skus = array_keys(self::CATALOGUE);
        $customers = ['Sara A.', 'Mona K.', 'Yousef R.', 'Layla H.', 'Omar B.', 'Huda N.'];
        $n = 0;

        // ~90 days of history, a couple of orders most days.
        for ($day = 89; $day >= 0; $day--) {
            $ordersToday = random_int(0, 3);

            for ($k = 0; $k < $ordersToday; $k++) {
                $store = random_int(0, 2) === 0 ? $shopify : $salla;
                $placedAt = Carbon::now()->subDays($day)->setTime(random_int(8, 21), random_int(0, 59));

                $lines = [];
                $picks = random_int(1, 3);
                $total = 0.0;
                for ($p = 0; $p < $picks; $p++) {
                    $sku = $skus[array_rand($skus)];
                    $qty = random_int(1, 3);
                    [$name, $price] = self::CATALOGUE[$sku];
                    $lines[] = ['sku' => $sku, 'name' => $name, 'qty' => $qty, 'price' => $price];
                    $total += $price * $qty;
                }

                $order = Order::create([
                    'store_id' => $store->id,
                    'external_id' => 'PFD-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'status' => self::COMMITTED[array_rand(self::COMMITTED)],
                    'total' => round($total, 2),
                    'currency' => $baseCurrency,
                    'customer_name' => $customers[array_rand($customers)],
                    'customer_email' => 'customer'.($n % 20).'@example.com',
                    'placed_at' => $placedAt,
                    // created_at stays "now" on purpose — a bulk history import. Analytics must still
                    // bucket by placed_at (defect #7), not by this insert time.
                ]);

                foreach ($lines as $i => $line) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'external_id' => $order->external_id.'-L'.$i,
                        'name' => $line['name'],
                        'sku' => $line['sku'],
                        'quantity' => $line['qty'],
                        'price' => $line['price'],
                    ]);
                }

                // Materialise the rollup exactly as the live sync path does.
                $feeEstimator->estimate($order->fresh());
                $calculator->calculate($order->fresh());

                $n++;
            }
        }

        $this->seedOverheads($org);

        $this->command?->info("ProfitDemoSeeder: {$n} demo orders with materialised P&L for org #{$org->id}.");
    }

    private function organization(): Organization
    {
        $org = Organization::where('slug', 'hubbyglobal-demo')->first();
        if ($org) {
            return $org;
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@hubbyglobal.com'],
            ['name' => 'Admin User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $org = Organization::create([
            'slug' => 'hubbyglobal-demo',
            'name' => 'HubbyGlobal Demo',
            'owner_id' => $user->id,
        ]);
        if (! $org->users()->where('user_id', $user->id)->exists()) {
            $org->users()->attach($user, ['role' => 'admin']);
        }

        return $org;
    }

    /** @return array{0: Store, 1: Store} salla, shopify — created if the demo run stands alone. */
    private function stores(Organization $org): array
    {
        $salla = Store::firstOrCreate(
            ['organization_id' => $org->id, 'platform' => 'salla'],
            ['name' => 'Salla Boutique', 'status' => 'connected'],
        );
        $shopify = Store::firstOrCreate(
            ['organization_id' => $org->id, 'platform' => 'shopify'],
            ['name' => 'Main Shopify Store', 'status' => 'connected', 'is_master' => true],
        );

        return [$salla, $shopify];
    }

    /**
     * Products, variants, a fixed cost definition and a FIFO layer per SKU. Fields the observers
     * would normally compute (landed cost, variant organization_id) are set explicitly, because
     * DatabaseSeeder runs WithoutModelEvents.
     */
    private function seedCatalogue(Organization $org): void
    {
        foreach (self::CATALOGUE as $sku => [$name, $price, $cost]) {
            $product = Product::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku],
                ['name' => $name, 'price' => $price, 'status' => 'active'],
            );

            ProductVariant::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku],
                ['product_id' => $product->id, 'price' => $price, 'stock' => 500],
            );

            if ($cost === null) {
                continue; // HONEY-SIDR-500 stays uncosted, to drive the coverage banner.
            }

            $costStr = number_format($cost, 4, '.', '');
            ProductCost::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku, 'store_id' => null, 'valid_from' => '2026-01-01'],
                [
                    'method' => 'fixed',
                    'unit_cost' => $costStr,
                    'landed_unit_cost' => $costStr,
                    'currency' => $org->base_currency ?? 'SAR',
                    'fx_rate_to_base' => 1,
                    'landed_unit_cost_base' => $costStr,
                    'source' => 'manual',
                ],
            );

            // A single deep FIFO layer acquired before any order, so COGS is measured, not estimated.
            CostLayer::updateOrCreate(
                ['organization_id' => $org->id, 'sku' => $sku, 'source_ref' => 'demo-opening-stock'],
                [
                    'source' => 'manual',
                    'acquired_at' => '2026-01-01 00:00:00',
                    'qty_received' => 100000,
                    'qty_remaining' => 100000,
                    'unit_cost' => $costStr,
                    'currency' => $org->base_currency ?? 'SAR',
                    'fx_rate_to_base' => 1,
                    'unit_cost_base' => $costStr,
                ],
            );
        }
    }

    /**
     * Recurring overheads + a run of advertising spend, so the P&L's operating-expenses and
     * advertising lines aren't empty in the demo. Amortized to daily slices for reporting.
     */
    private function seedOverheads(Organization $org): void
    {
        $start = \Illuminate\Support\Carbon::now()->subDays(89)->startOfDay();

        $expenses = [
            ['name' => 'SaaS & tools', 'category' => 'software', 'amount' => 1200, 'recurrence' => 'monthly'],
            ['name' => 'Warehouse rent', 'category' => 'rent', 'amount' => 6000, 'recurrence' => 'monthly'],
            ['name' => 'Packaging supplies', 'category' => 'packaging', 'amount' => 900, 'recurrence' => 'monthly'],
        ];
        foreach ($expenses as $e) {
            Expense::updateOrCreate(
                ['organization_id' => $org->id, 'name' => $e['name']],
                [
                    'category' => $e['category'],
                    'type' => 'recurring',
                    'recurrence' => $e['recurrence'],
                    'amount' => $e['amount'],
                    'amount_base' => $e['amount'],
                    'currency' => $org->base_currency ?? 'SAR',
                    'fx_rate_to_base' => 1,
                    'starts_on' => $start->toDateString(),
                    'amortize' => true,
                    'allocation_method' => 'revenue',
                ],
            );
        }

        // A meta ad campaign running most days over the window.
        for ($day = 89; $day >= 0; $day--) {
            $date = \Illuminate\Support\Carbon::now()->subDays($day)->toDateString();
            if ($day % 3 === 0) {
                continue; // not every day
            }
            $spend = 80 + ($day % 5) * 25;
            AdSpend::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'spend_key' => AdSpend::buildSpendKey('meta', 'demo-brand', null, $date, null),
                ],
                [
                    'channel' => 'meta',
                    'campaign_name' => 'Brand — always on',
                    'campaign_external_id' => 'demo-brand',
                    'date' => $date,
                    'spend' => $spend,
                    'currency' => $org->base_currency ?? 'SAR',
                    'fx_rate_to_base' => 1,
                    'spend_base' => $spend,
                    'source' => 'manual',
                ],
            );
        }

        app(\App\Services\Profit\ExpenseAmortizer::class)
            ->amortize($org, $start->toDateString(), \Illuminate\Support\Carbon::now()->addDay()->toDateString());
    }

    /** Modelled platform fees so the P&L reflects channel take-rates. */
    private function seedFeeRules(Organization $org): void
    {
        $rules = [
            ['platform' => 'salla',   'type' => OrderFee::TYPE_COMMISSION, 'basis' => FeeRule::BASIS_PERCENT_OF_ITEM,  'rate' => '7.0000'],
            ['platform' => 'salla',   'type' => OrderFee::TYPE_PAYMENT,    'basis' => FeeRule::BASIS_PERCENT_OF_ORDER, 'rate' => '2.7500'],
            ['platform' => 'shopify', 'type' => OrderFee::TYPE_PAYMENT,    'basis' => FeeRule::BASIS_PERCENT_OF_ORDER, 'rate' => '2.9000'],
        ];

        foreach ($rules as $rule) {
            FeeRule::updateOrCreate(
                ['organization_id' => $org->id, 'platform' => $rule['platform'], 'type' => $rule['type'], 'store_id' => null],
                [
                    'basis' => $rule['basis'],
                    'rate' => $rule['rate'],
                    'effective_from' => '2026-01-01',
                    'is_active' => true,
                ],
            );
        }
    }
}
