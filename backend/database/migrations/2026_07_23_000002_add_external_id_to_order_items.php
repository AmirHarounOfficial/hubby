<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes defect #2: SyncOrdersJob keys line items on a per-line `external_id` (so re-syncing an
 * order updates its lines instead of duplicating them, and Returns can map a marketplace refund
 * back to a specific line), but the column never existed — every order with items broke the sync.
 *
 * Adds the column and an index on (order_id, external_id) for the upsert lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'external_id')) {
                $table->string('external_id')->nullable()->after('order_id');
                $table->index(['order_id', 'external_id'], 'order_items_order_external_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_external_idx');
            $table->dropColumn('external_id');
        });
    }
};
