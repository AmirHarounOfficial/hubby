<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate-shop results (spec 04 §3.8). Persisted (not just cached) so we can show "you picked the 3rd
 * cheapest" and audit cost decisions. request_hash is the cache key over origin+dest+weight+dims+cod.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->foreignId('carrier_account_id')->constrained()->cascadeOnDelete();
            $table->string('carrier_code', 32);
            $table->string('service_code', 64);
            $table->string('service_name', 120)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('SAR');
            $table->decimal('cod_fee', 15, 2)->default(0);
            $table->decimal('fuel_surcharge', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->unsignedTinyInteger('transit_days_min')->nullable();
            $table->unsignedTinyInteger('transit_days_max')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->boolean('is_estimate')->default(false);
            $table->unsignedTinyInteger('rank')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamp('expires_at');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['request_hash', 'expires_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index('order_id');
            $table->index('shipment_id');
            $table->index(['carrier_code', 'service_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
