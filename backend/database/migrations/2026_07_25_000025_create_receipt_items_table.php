<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt lines (spec 08 §3.6). qty_damaged is received but NOT sellable — it never raises stock,
 * it only shows up in the discrepancy report. qty_expected null marks an unexpected line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 120)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('unidentified_barcode', 160)->nullable(); // received but unresolvable
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('qty_expected')->nullable();
            $table->unsignedInteger('qty_received')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);
            $table->integer('discrepancy')->default(0);
            $table->string('discrepancy_reason', 32)->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->timestamps();

            $table->index('receipt_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_items');
    }
};
