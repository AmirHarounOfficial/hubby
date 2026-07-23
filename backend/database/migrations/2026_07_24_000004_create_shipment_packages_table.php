<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Physical parcels within a shipment (spec 04 §3.4) — one shipment, n packages, n AWBs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('tracking_number', 64)->nullable();
            $table->string('carrier_package_id', 120)->nullable();
            $table->string('package_type', 32)->default('box');
            $table->decimal('weight_kg', 10, 3)->default(0);
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('volumetric_weight_kg', 10, 3)->nullable();
            $table->decimal('chargeable_weight_kg', 10, 3)->nullable();
            $table->decimal('declared_value', 15, 2)->default(0);
            $table->string('contents_description', 255)->nullable();
            $table->string('reference', 64)->nullable();
            $table->timestamps();

            $table->index('shipment_id');
            $table->unique(['shipment_id', 'sequence']);
            $table->index('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
