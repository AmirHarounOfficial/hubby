<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fee estimation rules (spec 01 §3.3).
 *
 * The honest fallback: most marketplaces never tell us what they charged per order. Rather than
 * pretend fees are zero — which silently overstates profit — the merchant states the rule once
 * and we apply it, with every resulting fee flagged `is_estimated`.
 *
 * `organization_id` null means a system-wide default shipped in a seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('platform', 32); // matches stores.platform
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 191)->nullable();

            $table->string('type', 24);                 // same enumeration as order_fees.type
            $table->string('subtype', 64)->nullable();
            // percent_of_item | percent_of_order | flat_per_order | flat_per_unit | per_kg
            $table->string('basis', 24)->default('percent_of_item');
            $table->decimal('rate', 9, 4)->default(0);  // 15.0000 = 15%
            $table->decimal('min_amount', 15, 4)->nullable();
            $table->decimal('max_amount', 15, 4)->nullable();
            $table->char('currency', 3)->nullable();    // required for flat bases

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(100);  // lower wins
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'platform', 'type', 'effective_from'], 'px_feerules_match');
            $table->index(['platform', 'type', 'is_active'], 'px_feerules_platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rules');
    }
};
