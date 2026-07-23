<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipments (spec 04 §3.3) — the spine of the fulfilment lifecycle. status uses the normalized
 * tracking vocabulary (§4.2) plus the pre-transit states we own. COD is first-class (a shipment is a
 * collection instruction in MENA). manifest_id / pickup_request_id / rate_id are forward references
 * to tables that land in later slices, so they are plain nullable columns until then; their FK
 * constraints are added when those tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('carrier_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('carrier_code', 32)->nullable();
            $table->string('service_code', 64)->nullable();
            $table->string('service_name', 120)->nullable();
            $table->enum('direction', ['outbound', 'return', 'rto'])->default('outbound');
            $table->string('reference', 40);
            $table->string('tracking_number', 64)->nullable();
            $table->string('carrier_shipment_id', 120)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('carrier_status_raw', 120)->nullable();
            $table->string('carrier_status_code', 40)->nullable();
            $table->foreignId('ship_from_address_id')->nullable()->constrained('order_addresses')->nullOnDelete();
            $table->foreignId('ship_to_address_id')->nullable()->constrained('order_addresses')->nullOnDelete();
            $table->foreignId('return_to_address_id')->nullable()->constrained('order_addresses')->nullOnDelete();
            $table->unsignedSmallInteger('package_count')->default(1);
            $table->decimal('total_weight_kg', 10, 3)->default(0);
            $table->decimal('declared_value', 15, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->boolean('is_cod')->default(false);
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->string('cod_currency', 3)->nullable();
            $table->decimal('cod_collected_amount', 15, 2)->nullable();
            $table->timestamp('cod_collected_at')->nullable();
            $table->timestamp('cod_remitted_at')->nullable();
            $table->decimal('shipping_cost', 15, 2)->nullable();
            $table->string('shipping_cost_currency', 3)->nullable();
            $table->decimal('charged_to_customer', 15, 2)->default(0);
            $table->decimal('insurance_amount', 15, 2)->default(0);
            $table->string('incoterm', 8)->nullable();
            $table->string('contents_description', 255)->nullable();
            $table->string('pieces_description', 255)->nullable();
            $table->string('special_instructions', 500)->nullable();
            $table->unsignedBigInteger('manifest_id')->nullable();
            $table->unsignedBigInteger('pickup_request_id')->nullable();
            $table->unsignedBigInteger('rate_id')->nullable();
            $table->enum('label_format', ['pdf', 'zpl', 'png'])->default('pdf');
            $table->string('tracking_url', 500)->nullable();
            $table->string('public_tracking_slug', 48)->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_tracked_at')->nullable();
            $table->unsignedSmallInteger('tracking_poll_attempts')->default(0);
            $table->timestamp('pushed_to_platform_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_code', 48)->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['carrier_code', 'tracking_number']);
            $table->unique('public_tracking_slug');
            $table->index(['organization_id', 'status']);
            $table->index(['store_id', 'status']);
            $table->index('order_id');
            $table->index('return_request_id');
            $table->index(['carrier_account_id', 'status']);
            $table->index('manifest_id');
            $table->index(['is_cod', 'cod_remitted_at']);
            $table->index(['status', 'last_tracked_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
