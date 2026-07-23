<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight + dimensions on products/variants (spec 04 §3.12). Rate shopping is meaningless without
 * them. Variant values win; product values are the fallback; a per-org default parcel weight is the
 * last resort (§4.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'weight_kg')) {
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->decimal('length_cm', 8, 2)->nullable();
                $table->decimal('width_cm', 8, 2)->nullable();
                $table->decimal('height_cm', 8, 2)->nullable();
                $table->string('hs_code', 16)->nullable();
                $table->string('country_of_origin', 2)->nullable();
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'weight_kg')) {
                $table->decimal('weight_kg', 10, 3)->nullable();
                $table->decimal('length_cm', 8, 2)->nullable();
                $table->decimal('width_cm', 8, 2)->nullable();
                $table->decimal('height_cm', 8, 2)->nullable();
                $table->string('barcode', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'hs_code', 'country_of_origin'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('product_variants', function (Blueprint $table) {
            foreach (['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'barcode'] as $col) {
                if (Schema::hasColumn('product_variants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
