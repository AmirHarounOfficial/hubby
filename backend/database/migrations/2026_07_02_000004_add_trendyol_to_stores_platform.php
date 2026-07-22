<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the platform enum to include Trendyol. Enum modification is
        // MySQL-specific.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE stores MODIFY platform "
                . "ENUM('shopify','salla','zid','woocommerce','amazon','noon','trendyol') NOT NULL"
            );

            return;
        }

        // sqlite (tests) compiles an enum into a CHECK constraint listing the
        // original four platforms, and it can't be widened in place — so the
        // marketplace migrations skipped it and creating an amazon/noon/trendyol
        // store blew up on the constraint. Rebuild the column as a plain string;
        // the allowed set is enforced by request validation either way.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('platform')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE stores MODIFY platform "
                . "ENUM('shopify','salla','zid','woocommerce','amazon','noon') NOT NULL"
            );
        }
    }
};
