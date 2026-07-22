<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIFO inventory layers (spec 01 §3.3, §4.3).
 *
 * One row per receipt of stock at a known cost. Sales consume layers oldest-first, which is
 * what makes COGS reflect real purchase prices as they change.
 *
 * `fx_rate_to_base` is frozen at acquisition — a layer's cost never re-rates, otherwise last
 * quarter's margin would move every time the exchange rate does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 191);
            // null = shared org-wide pool (the default).
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');

            // opening|purchase_order|manual|import|return_restock|adjustment|estimated
            $table->string('source', 24)->default('manual');
            $table->string('source_ref', 191)->nullable(); // PO number, import batch id

            $table->dateTime('acquired_at');               // FIFO ordering key
            $table->integer('qty_received')->default(0);
            $table->integer('qty_remaining')->default(0);  // >= 0, decremented on consumption

            $table->decimal('unit_cost', 15, 4)->default(0); // landed, in `currency`
            $table->char('currency', 3)->default('SAR');
            $table->decimal('fx_rate_to_base', 18, 8)->default(1);
            $table->decimal('unit_cost_base', 15, 4)->default(0);

            // true when synthesised by the shortfall path (selling stock we have no layer for)
            $table->boolean('is_estimated')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // `id` is part of the FIFO index because two receipts can share an acquired_at;
            // without a deterministic tiebreak, FIFO is not reproducible.
            $table->index(['organization_id', 'sku', 'acquired_at', 'id'], 'px_layers_fifo');
            $table->index(['organization_id', 'sku', 'qty_remaining'], 'px_layers_open');
            $table->index(['organization_id', 'store_id'], 'px_layers_store');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_layers');
    }
};
