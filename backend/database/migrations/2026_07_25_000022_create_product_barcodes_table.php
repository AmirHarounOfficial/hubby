<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product barcodes (spec 08 §3.3) — the heart of scanning. One item may carry many barcodes
 * (manufacturer EAN, our own Code128, an Amazon FNSKU), but a barcode resolves to exactly ONE
 * sellable item within an organization. Cross-tenant collisions are expected and fine: two orgs
 * legitimately sell the same EAN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('barcode', 128);          // stored normalised
            $table->string('barcode_raw', 160)->nullable();
            $table->string('symbology', 24)->default('unknown');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // provenance only
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('pack_size')->default(1); // a case barcode adds pack_size units
            $table->string('source', 24)->default('manual');  // manual|import|sync|scan_learned
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'barcode']); // the resolution index
            $table->index('product_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
