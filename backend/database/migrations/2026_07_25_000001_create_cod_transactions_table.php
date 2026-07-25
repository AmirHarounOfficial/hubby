<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The COD ledger (spec 06 §3.2): one row per COD order tracking the cash a carrier must collect,
 * what it actually collected, and what it remitted to the merchant. COD dominates MENA and nobody
 * models it — this is the row that turns "did the carrier pay me?" into a query.
 *
 * remittance_id / shipment_id are plain nullable columns here (their tables are owned by other specs /
 * later slices); real FK constraints are added in a guarded follow-up.
 *
 * down() destroys reconciliation history — never run in production without an export first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cod_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shipment_id')->nullable();
            $table->unsignedBigInteger('remittance_id')->nullable();
            $table->string('carrier_code', 40)->nullable();
            $table->string('awb_number', 64)->nullable();          // primary match key; normalised
            $table->string('carrier_order_ref', 120)->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->decimal('expected_amount', 15, 2);
            $table->decimal('collected_amount', 15, 2)->nullable();
            $table->decimal('remitted_amount', 15, 2)->nullable();
            $table->decimal('carrier_cod_fee', 15, 2)->default(0);
            $table->decimal('carrier_shipping_fee', 15, 2)->default(0);
            $table->decimal('carrier_rto_fee', 15, 2)->default(0);
            $table->decimal('variance_amount', 15, 2)->default(0);   // collected − expected
            $table->string('status', 32)->default('pending');
            $table->string('match_type', 20)->nullable();
            $table->decimal('match_confidence', 5, 2)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('remitted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('rto_reason_code', 64)->nullable();
            $table->string('customer_key', 191)->nullable();
            $table->string('delivery_city', 120)->nullable();
            $table->boolean('is_disputed')->default(false);
            $table->text('dispute_note')->nullable();
            $table->timestamp('fees_posted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'carrier_code', 'status']);
            $table->index(['organization_id', 'due_at']);
            $table->index(['organization_id', 'customer_key']);
            $table->index('awb_number');
            $table->index('remittance_id');
            $table->index(['organization_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_transactions');
    }
};
