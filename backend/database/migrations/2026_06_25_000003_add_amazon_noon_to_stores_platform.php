<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the platform enum to include Amazon and Noon. Enum modification
        // is MySQL-specific; sqlite (tests) stores enums as plain strings.
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
