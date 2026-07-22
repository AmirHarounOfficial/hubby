<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes defect #5 (called "D3" in the profit spec): `product_variants.sku` was globally
 * `->unique()`, so two organizations could never use the same SKU — a hard multi-tenant blocker.
 *
 * The whole system already treats SKU as unique *within* an organization (CostResolver and the
 * profit rollups key on `(organization_id, sku)`), so we denormalize `organization_id` onto the
 * variant, backfill it from the parent product, and swap the global unique for a per-org one.
 * The column is kept in sync going forward by ProductVariant's `creating` hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_variants', 'organization_id')) {
            Schema::table('product_variants', function (Blueprint $table) {
                // No DB-level FK: adding one to an existing table forces a full rebuild on SQLite
                // (tests) and the app already scopes every query. Matches the denormalised
                // organization_id used across the profit rollup tables.
                $table->unsignedBigInteger('organization_id')->nullable()->after('product_id');
            });
        }

        // Backfill from the parent product. Correlated subquery works on both MySQL and SQLite.
        DB::table('product_variants')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(select organization_id from products where products.id = product_variants.product_id)'
                ),
            ]);

        Schema::table('product_variants', function (Blueprint $table) {
            // Drops index `product_variants_sku_unique`.
            $table->dropUnique(['sku']);
            $table->unique(['organization_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'sku']);
            $table->unique('sku');
            $table->dropColumn('organization_id');
        });
    }
};
