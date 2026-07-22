<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the platform enum to include Amazon and Noon. Enum modification
        // is MySQL-specific; on sqlite the enum's CHECK constraint is relaxed by
        // the later add_trendyol_to_stores_platform migration.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE stores MODIFY platform "
                . "ENUM('shopify','salla','zid','woocommerce','amazon','noon') NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE stores MODIFY platform "
                . "ENUM('shopify','salla','zid','woocommerce') NOT NULL"
            );
        }
    }
};
