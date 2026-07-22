<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost definitions per SKU (spec 01 §3.3).
 *
 * The authoritative record of what a merchant says a unit costs. FIFO *actual* layers live
 * in `cost_layers`; this table holds the method choice and the fixed/period/batch figures.
 *
 * Keyed on (organization_id, sku) — never SKU alone. `product_variants.sku` is currently
 * globally unique across tenants (defect D3), so any SKU-only key would collide or leak
 * between organizations.
 *
 * Money is decimal(15,4), not (15,2): these values get divided per unit and accumulated,
 * and 2 dp loses cents on division.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 191);
            // null store_id = applies to every store; set = per-channel override.
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');

            $table->string('method', 16)->default('fixed'); // fixed | fifo | period | batch

            // Landed cost components, all per unit and ex-VAT.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('freight_cost', 15, 4)->default(0);
            $table->decimal('duty_cost', 15, 4)->default(0);
            $table->decimal('prep_cost', 15, 4)->default(0);
            $table->decimal('other_cost', 15, 4)->default(0);
            // Maintained by ProductCostObserver = sum of the five above. Deliberately not a
            // generated column: sqlite (tests) and MySQL disagree on storedAs syntax.
            $table->decimal('landed_unit_cost', 15, 4)->default(0);

            $table->char('currency', 3)->default('SAR');
            $table->decimal('fx_rate_to_base', 18, 8)->default(1);
            $table->decimal('landed_unit_cost_base', 15, 4)->default(0);

            $table->date('valid_from');                 // inclusive
            $table->date('valid_to')->nullable();       // exclusive; closed off by CostResolver
            $table->string('batch_ref', 64)->nullable();   // method = batch
            $table->date('period_end')->nullable();        // method = period

            $table->string('source', 16)->default('manual'); // manual|import|purchase_order|api|estimated
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes(); // never hard-delete cost history

            $table->index(['organization_id', 'sku', 'valid_from'], 'px_costs_lookup');
            $table->index(['organization_id', 'store_id', 'sku'], 'px_costs_store');
            $table->index('product_variant_id', 'px_costs_variant');
            // NOTE: MySQL treats NULL as distinct in a unique index, so this does NOT stop two
            // org-wide (store_id = null) rows for the same (sku, valid_from).
            // ProductCostService::upsert() does a lockForUpdate() existence check in-transaction.
            $table->unique(['organization_id', 'sku', 'store_id', 'valid_from'], 'ux_costs_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costs');
    }
};
