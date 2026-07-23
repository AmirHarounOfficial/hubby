<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which order lines sit in which package (spec 04 §3.5) — needed for partial fulfilment and accurate
 * packing slips. return_item_id is a forward reference (Spec 03 return_items) kept FK-less here since
 * the shipment/return wiring lands in a later slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_package_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('return_item_id')->nullable();
            $table->string('sku', 255)->nullable();
            $table->string('name', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_weight_kg', 10, 3)->nullable();
            $table->decimal('unit_value', 15, 2)->default(0);
            $table->string('hs_code', 16)->nullable();
            $table->string('country_of_origin', 2)->nullable();
            $table->timestamps();

            $table->index('shipment_id');
            $table->index('shipment_package_id');
            $table->index('order_item_id');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
