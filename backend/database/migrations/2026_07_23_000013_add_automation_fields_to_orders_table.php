<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-side columns the automation actions write (spec 02 §3.4). Guarded with hasColumn per the
 * house pattern. `split_order` columns (parent_order_id/split_index) are included so no later
 * migration is needed when that action ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'tags')) {
                $table->json('tags')->nullable();
            }
            if (! Schema::hasColumn('orders', 'fulfillment_location')) {
                $table->string('fulfillment_location', 100)->nullable();
            }
            if (! Schema::hasColumn('orders', 'carrier_code')) {
                $table->string('carrier_code', 60)->nullable();
            }
            if (! Schema::hasColumn('orders', 'shipping_service')) {
                $table->string('shipping_service', 60)->nullable();
            }
            if (! Schema::hasColumn('orders', 'folder')) {
                $table->string('folder', 60)->nullable();
            }
            if (! Schema::hasColumn('orders', 'is_held')) {
                $table->boolean('is_held')->default(false);
            }
            if (! Schema::hasColumn('orders', 'hold_reason')) {
                $table->string('hold_reason', 255)->nullable();
            }
            if (! Schema::hasColumn('orders', 'held_at')) {
                $table->timestamp('held_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'parent_order_id')) {
                $table->unsignedBigInteger('parent_order_id')->nullable();
            }
            if (! Schema::hasColumn('orders', 'split_index')) {
                $table->unsignedSmallInteger('split_index')->nullable();
            }
            if (! Schema::hasColumn('orders', 'automation_state')) {
                $table->json('automation_state')->nullable();
            }
            if (! Schema::hasColumn('orders', 'internal_notes')) {
                $table->json('internal_notes')->nullable();
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('is_held');
            $table->index('parent_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['is_held']);
            $table->dropIndex(['parent_order_id']);
            $table->dropColumn([
                'tags', 'fulfillment_location', 'carrier_code', 'shipping_service', 'folder',
                'is_held', 'hold_reason', 'held_at', 'parent_order_id', 'split_index',
                'automation_state', 'internal_notes',
            ]);
        });
    }
};
