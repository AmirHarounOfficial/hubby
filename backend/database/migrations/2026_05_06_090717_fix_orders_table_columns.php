<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This patch reconciles an older `orders` schema (which had `total_price`
     * and `customer_info`) with the current one. The create-orders migration
     * has since been updated to the final shape, so every step here is guarded
     * to be a no-op when the columns already exist — keeping `migrate:fresh`
     * working while still upgrading any legacy database.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'total_price') && !Schema::hasColumn('orders', 'total')) {
                $table->renameColumn('total_price', 'total');
            }
            if (Schema::hasColumn('orders', 'customer_info')) {
                $table->dropColumn('customer_info');
            }
            if (!Schema::hasColumn('orders', 'customer_name')) {
                $table->string('customer_name')->nullable();
            }
            if (!Schema::hasColumn('orders', 'customer_email')) {
                $table->string('customer_email')->nullable();
            }
            if (!Schema::hasColumn('orders', 'raw_data')) {
                $table->json('raw_data')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'total') && !Schema::hasColumn('orders', 'total_price')) {
                $table->renameColumn('total', 'total_price');
            }
            foreach (['customer_name', 'customer_email', 'raw_data'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
