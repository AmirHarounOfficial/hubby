<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization carrier accounts (spec 04 §3.1). Credentials are far more sensitive than the
 * store integration tokens — they can create *billable* shipments — so `credentials` is an
 * `encrypted:array` cast at the model and never leaves the API as anything but `has_credentials`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('carrier_code', 32); // aramex|smsa|naqel|jnt|torod|dhl|fedex|manual
            $table->string('label', 120);
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->text('credentials'); // JSON, encrypted:array cast
            $table->string('account_number', 64)->nullable();
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('ship_from_address_id')->nullable(); // FK added with order_addresses below
            $table->json('supported_services')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('cod_enabled')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'carrier_code', 'label']);
            $table->index(['organization_id', 'is_active']);
            $table->index('carrier_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_accounts');
    }
};
