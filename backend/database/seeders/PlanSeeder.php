<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Trial',
                'slug' => 'trial',
                'price' => 0,
                'store_limit' => 2,
                'order_limit' => 500,
                // Pass a plain array — the Plan model's `array` cast handles JSON encoding.
                'features' => ['Shopify & Salla', 'Basic Analytics', '14 Days Trial'],
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 29,
                'store_limit' => 5,
                'order_limit' => 5000,
                'features' => ['Shopify, Salla & WooCommerce', 'Advanced Analytics', 'Email Support'],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 79,
                'store_limit' => 15,
                'order_limit' => 50000,
                'features' => ['All Platforms', 'Full Parity', 'Priority Support', 'Custom Integrations'],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 199,
                'store_limit' => 999,
                'order_limit' => 999999,
                'features' => ['Unlimited Everything', 'Dedicated Account Manager', 'SLA', 'White-labeling'],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
