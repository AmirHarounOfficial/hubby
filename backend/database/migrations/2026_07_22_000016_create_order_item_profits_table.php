<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized per-line profit rollup (spec 01 §3.3).
 *
 * Required so per-SKU profit can be read without re-running fee allocation on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_profits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('sku', 191)->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->date('placed_on');

            $table->integer('quantity')->default(0);
            $table->decimal('net_revenue_base', 15, 4)->default(0);
            $table->decimal('vat_base', 15, 4)->default(0);
            $table->decimal('cogs_base', 15, 4)->default(0);
            $table->decimal('direct_fees_base', 15, 4)->default(0);    // booked to this line
            $table->decimal('allocated_fees_base', 15, 4)->default(0); // share of order-level fees
            $table->decimal('ad_spend_base', 15, 4)->default(0);
            $table->decimal('net_profit_base', 15, 4)->default(0);
            $table->decimal('margin_pct', 9, 4)->nullable();
            $table->boolean('is_estimated')->default(false);
            $table->timestamps();

            $table->unique('order_item_id');
            $table->index(['organization_id', 'sku', 'placed_on'], 'px_itemprofit_sku');
            $table->index(['organization_id', 'placed_on'], 'px_itemprofit_period');
            $table->index(['organization_id', 'store_id', 'placed_on'], 'px_itemprofit_store');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_profits');
    }
};
