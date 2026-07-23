<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fulfilment + COD fields on orders (spec 04 §3.12). COD is the default payment method in MENA, so a
 * shipment is a collection instruction: the order needs to carry the amount and the flag. Every add
 * is guarded — placed_at was already introduced by the profit engine work, and this must not clash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 32)->nullable();
            }
            if (! Schema::hasColumn('orders', 'is_cod')) {
                $table->boolean('is_cod')->default(false);
            }
            if (! Schema::hasColumn('orders', 'cod_amount')) {
                $table->decimal('cod_amount', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('orders', 'shipping_total')) {
                $table->decimal('shipping_total', 15, 2)->default(0);
            }
            if (! Schema::hasColumn('orders', 'fulfillment_status')) {
                $table->string('fulfillment_status', 24)->nullable();
            }
            if (! Schema::hasColumn('orders', 'shipments_count')) {
                $table->unsignedSmallInteger('shipments_count')->default(0);
            }
            if (! Schema::hasColumn('orders', 'placed_at')) {
                $table->timestamp('placed_at')->nullable();
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['store_id', 'fulfillment_status']);
            $table->index(['is_cod', 'fulfillment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'fulfillment_status']);
            $table->dropIndex(['is_cod', 'fulfillment_status']);
            foreach (['payment_method', 'is_cod', 'cod_amount', 'shipping_total', 'fulfillment_status', 'shipments_count'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
