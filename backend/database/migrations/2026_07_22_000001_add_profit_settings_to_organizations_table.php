<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profit engine settings at the tenant level (spec 01 §3.4).
 *
 * `base_currency` is the currency every `*_base` column across the profit tables is
 * expressed in — margins are meaningless until every channel's money is converted to
 * one unit. Defaults to SAR to match the frontend Money component and mobile formatter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'base_currency')) {
                $table->char('base_currency', 3)->default('SAR');
            }
            if (! Schema::hasColumn('organizations', 'default_vat_rate')) {
                // KSA standard rate. 4 dp so fractional rates stay exact.
                $table->decimal('default_vat_rate', 6, 4)->default(0.1500);
            }
            if (! Schema::hasColumn('organizations', 'prices_include_vat')) {
                // MENA storefronts quote VAT-inclusive prices by default.
                $table->boolean('prices_include_vat')->default(true);
            }
            if (! Schema::hasColumn('organizations', 'cost_visibility_role')) {
                // Minimum org role allowed to see cost/margin data.
                $table->string('cost_visibility_role', 16)->default('admin');
            }
            if (! Schema::hasColumn('organizations', 'default_cost_method')) {
                $table->string('default_cost_method', 16)->default('fixed');
            }
            if (! Schema::hasColumn('organizations', 'allocate_ads_to_orders')) {
                $table->boolean('allocate_ads_to_orders')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach ([
                'base_currency',
                'default_vat_rate',
                'prices_include_vat',
                'cost_visibility_role',
                'default_cost_method',
                'allocate_ads_to_orders',
            ] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
