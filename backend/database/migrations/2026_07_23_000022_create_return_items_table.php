<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line return detail (spec 03 §3.3). Quantities flow requested → approved → received →
 * restocked/scrapped; the disposition set at inspection drives the inventory effect. One RMA line
 * per order line — a second return of the same order line becomes a new RMA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 255)->nullable();
            $table->string('name', 255);
            $table->unsignedInteger('quantity_requested');
            $table->unsignedInteger('quantity_approved')->default(0);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_restocked')->default(0);
            $table->unsignedInteger('quantity_scrapped')->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->string('reason_code', 48)->nullable();
            $table->text('reason_note')->nullable();
            // new|opened|used|damaged|defective|wrong_item|missing_parts|unknown
            $table->string('condition', 24)->default('unknown');
            // restock|scrap|quarantine|return_to_vendor|repair|pending
            $table->string('disposition', 24)->default('pending');
            $table->text('inspection_note')->nullable();
            $table->foreignId('exchange_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('inventory_log_id')->nullable()->constrained('inventory_logs')->nullOnDelete();
            $table->foreignId('scrap_inventory_log_id')->nullable()->constrained('inventory_logs')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('restocked_at')->nullable();
            $table->timestamps();

            $table->index('return_request_id');
            $table->index('sku');
            $table->index('product_variant_id');
            $table->index(['disposition', 'inspected_at']);
            $table->unique(['return_request_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
