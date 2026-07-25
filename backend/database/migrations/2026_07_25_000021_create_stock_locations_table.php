<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock locations (spec 08 §3.2) — the MINIMUM viable location model.
 *
 * Deliberately NOT authoritative for quantity (§3.9): stock stays the scalar on products/variants.
 * Locations tell a picker where to walk and prove where a count happened; per-bin quantities are
 * Phase 2 (`stock_location_quantities`). Every scan table already carries stock_location_id so that
 * migration is additive rather than a rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);           // A-01-3 — what's printed on the shelf label
            $table->string('name', 120)->nullable();
            $table->string('type', 24)->default('bin'); // bin|shelf|staging|receiving|packing|quarantine
            $table->string('barcode', 64)->nullable();
            $table->unsignedInteger('sequence')->default(0); // walk order
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id', 'code']);
            $table->unique(['organization_id', 'barcode']);
            $table->index(['warehouse_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
