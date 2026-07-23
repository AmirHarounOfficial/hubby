<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-store ship-from + shipping preferences (spec 04 §3.12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'default_ship_from_address_id')) {
                $table->foreignId('default_ship_from_address_id')->nullable()->constrained('order_addresses')->nullOnDelete();
            }
            if (! Schema::hasColumn('stores', 'shipping_settings')) {
                $table->json('shipping_settings')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'default_ship_from_address_id')) {
                $table->dropConstrainedForeignId('default_ship_from_address_id');
            }
            if (Schema::hasColumn('stores', 'shipping_settings')) {
                $table->dropColumn('shipping_settings');
            }
        });
    }
};
