<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored label artefacts (spec 04 §3.6). Separate from shipments because one shipment can have many
 * artefacts (per package, per format, reprints). We ALWAYS keep our own copy — a carrier's temporary
 * label URL is never the only source; it goes in shipments.raw_response for forensics only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_package_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['label', 'packing_slip', 'manifest', 'commercial_invoice', 'return_label'])->default('label');
            $table->enum('format', ['pdf', 'zpl', 'png', 'epl', 'html'])->default('pdf');
            $table->string('disk', 32)->default('labels');
            $table->string('path', 500);
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->string('carrier_label_id', 120)->nullable();
            $table->unsignedSmallInteger('printed_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'type']);
            $table->index('shipment_package_id');
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};
