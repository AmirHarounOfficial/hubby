<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manifests (spec 04 §3.9) — the end-of-day handover document listing every shipment given to a
 * carrier. Shipments join a manifest via shipments.manifest_id. Carriers without a manifest API get
 * a locally generated document (carrier_manifest_id stays null so we know it isn't carrier-acked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_account_id')->constrained()->cascadeOnDelete();
            $table->string('carrier_code', 32);
            $table->string('reference', 40);
            $table->string('carrier_manifest_id', 120)->nullable();
            $table->enum('status', ['draft', 'submitted', 'confirmed', 'failed'])->default('draft');
            $table->unsignedInteger('shipment_count')->default(0);
            $table->decimal('total_weight_kg', 12, 3)->default(0);
            $table->date('manifest_date');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('raw_response')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'reference']);
            $table->index(['carrier_account_id', 'manifest_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
