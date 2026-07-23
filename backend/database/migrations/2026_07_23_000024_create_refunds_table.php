<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund records (spec 03 §3.5). A refund can exist without an RMA (goodwill). `cod_not_collected`
 * captures the RTO case where no money ever changed hands but the analytics still need a row to
 * distinguish "nothing to refund" from "not refunded yet". Keyed by a deterministic idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 120)->nullable();
            $table->string('issuer', 16)->default('merchant');      // merchant|marketplace|psp
            $table->string('method', 24)->default('original_payment'); // original_payment|store_credit|bank_transfer|cash|wallet|cod_not_collected
            $table->string('status', 16)->default('pending');       // pending|processing|succeeded|failed|cancelled
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('items_amount', 15, 2)->default(0);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('fee_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->string('gateway', 48)->nullable();
            $table->string('reason', 255)->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('idempotency_key', 64);
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['organization_id', 'status']);
            $table->index('order_id');
            $table->index('return_request_id');
            $table->index(['organization_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
