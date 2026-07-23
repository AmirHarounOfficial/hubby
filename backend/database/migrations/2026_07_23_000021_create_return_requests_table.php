<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RMA header (spec 03 §3.2). Unlike `orders`, returns carry a DENORMALIZED organization_id: return
 * lists are filtered/sorted heavily, and scoping through stores via whereHas would add a dependent
 * subquery to every query. A direct indexed organization_id is worth the denormalization.
 *
 * shipment FKs (return_shipment_id/outbound_shipment_id) are plain nullable columns for now — the
 * `shipments` table arrives with Spec 04, at which point a follow-up migration adds the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('rma_number', 32);
            $table->string('external_id', 120)->nullable();
            $table->string('type', 24)->default('customer_return'); // customer_return|rto|damage_claim|exchange
            $table->string('origin', 16)->default('dashboard');     // dashboard|portal|platform|carrier|api|mobile
            $table->string('status', 32)->default('requested');     // §4.1
            $table->string('resolution', 16)->default('none');      // refund|exchange|store_credit|repair|reject|none
            $table->string('reason_code', 48)->nullable();
            $table->text('reason_note')->nullable();
            $table->boolean('is_marketplace_managed')->default(false);
            $table->string('refund_responsibility', 16)->default('merchant'); // merchant|marketplace|none
            $table->string('currency', 3)->default('SAR');
            $table->decimal('items_subtotal', 15, 2)->default(0);
            $table->decimal('tax_refund', 15, 2)->default(0);
            $table->decimal('shipping_refund', 15, 2)->default(0);
            $table->decimal('restocking_fee', 15, 2)->default(0);
            $table->decimal('return_shipping_cost', 15, 2)->default(0);
            $table->string('return_shipping_paid_by', 16)->default('merchant'); // merchant|customer|marketplace
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->json('pickup_address')->nullable();
            $table->string('carrier_code', 32)->nullable();
            $table->string('tracking_number', 64)->nullable();
            $table->unsignedBigInteger('return_shipment_id')->nullable();  // FK added with Spec 04
            $table->unsignedBigInteger('outbound_shipment_id')->nullable();
            $table->foreignId('replacement_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('portal_token', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'rma_number']);
            $table->unique('portal_token');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'type', 'created_at']);
            $table->index(['store_id', 'status']);
            $table->index('order_id');
            $table->index('reason_code');
            $table->index('tracking_number');
            $table->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
    }
};
