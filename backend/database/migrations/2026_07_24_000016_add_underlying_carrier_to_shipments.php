<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torod is an aggregator (spec 04 §6.5): shipments.carrier_code is `torod` while the *actual* carrier
 * (SMSA, Aramex, iMile…) lives here, so tracking pages and analytics can show "SMSA (via Torod)".
 * Added with the Torod carrier, not in the base migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'underlying_carrier')) {
                $table->string('underlying_carrier', 32)->nullable()->after('carrier_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'underlying_carrier')) {
                $table->dropColumn('underlying_carrier');
            }
        });
    }
};
