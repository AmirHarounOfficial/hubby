<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes defect #7: analytics bucketed revenue/orders by `created_at` — the row insert time — but
 * orders had no order-date column. A first sync that pulls months of history therefore dumped it
 * all into "today", making revenue, the timeline, and period-over-period comparisons wrong.
 *
 * Adds `placed_at` (the merchant-facing order date from the platform). Analytics now reads
 * COALESCE(placed_at, created_at), so historical rows and any platform that doesn't supply a date
 * still fall back gracefully to insert time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'placed_at')) {
                $table->timestamp('placed_at')->nullable()->after('currency');
                $table->index('placed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['placed_at']);
            $table->dropColumn('placed_at');
        });
    }
};
