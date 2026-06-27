<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Gateway order reference, used to match an edfapay callback to a subscription.
            $table->string('external_ref')->nullable()->index()->after('plan_id');
        });

        // Add a "pending" state (awaiting gateway confirmation). Enum widening is
        // MySQL-specific; sqlite (tests) stores enums as plain strings already.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE subscriptions MODIFY status "
                . "ENUM('trial','active','past_due','cancelled','pending') NOT NULL DEFAULT 'trial'"
            );
        }
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('external_ref');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE subscriptions MODIFY status "
                . "ENUM('trial','active','past_due','cancelled') NOT NULL DEFAULT 'trial'"
            );
        }
    }
};
