<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pickup requests (spec 04 §3.10) — "send a driver". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_account_id')->constrained()->cascadeOnDelete();
            $table->string('carrier_code', 32);
            $table->string('reference', 40);
            $table->string('carrier_pickup_id', 120)->nullable();
            $table->enum('status', ['requested', 'confirmed', 'cancelled', 'completed', 'failed'])->default('requested');
            $table->foreignId('pickup_address_id')->nullable()->constrained('order_addresses')->nullOnDelete();
            $table->date('pickup_date');
            $table->time('ready_at')->nullable();
            $table->time('close_at')->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->decimal('total_weight_kg', 12, 3)->default(0);
            $table->string('instructions', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_response')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['carrier_account_id', 'pickup_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_requests');
    }
};
