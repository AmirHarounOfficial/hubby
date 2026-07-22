<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-channel overrides for the profit engine (spec 01 §3.4).
 *
 * All nullable: null means "inherit the organization setting". A store selling on a
 * marketplace that settles in a different currency, or in a different VAT regime,
 * overrides only what differs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'currency')) {
                $table->char('currency', 3)->nullable();
            }
            if (! Schema::hasColumn('stores', 'vat_rate')) {
                $table->decimal('vat_rate', 6, 4)->nullable();
            }
            if (! Schema::hasColumn('stores', 'prices_include_vat')) {
                $table->boolean('prices_include_vat')->nullable();
            }
            if (! Schema::hasColumn('stores', 'settlements_synced_at')) {
                $table->timestamp('settlements_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            foreach (['currency', 'vat_rate', 'prices_include_vat', 'settlements_synced_at'] as $column) {
                if (Schema::hasColumn('stores', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
