<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured addresses (spec 04 §3.2). Nothing persisted addresses before this — they lived only in
 * orders.raw_data. order_id is nullable so the same table holds ship-from / warehouse / return-to
 * addresses for carrier accounts and stores. district (حي) and short_address (Saudi National Address)
 * are first-class because the region's carriers require them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('type', ['ship_to', 'bill_to', 'ship_from', 'return_to'])->default('ship_to');
            $table->string('name', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('phone_alt', 32)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('line1', 255)->nullable();
            $table->string('line2', 255)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('city_normalized', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->default('SA');
            $table->string('short_address', 16)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_validated')->default(false);
            $table->string('validation_source', 32)->nullable();
            $table->json('validation_notes')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'type']);
            $table->index(['organization_id', 'type']);
            $table->index('city_normalized');
            $table->index('country_code');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_addresses');
    }
};
