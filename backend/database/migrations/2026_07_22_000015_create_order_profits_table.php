<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized per-order profit rollup (spec 01 §3.3).
 *
 * All reporting reads this table; nothing recomputes profit on request. That is what keeps a
 * 90-day P&L fast, and it means a margin figure is always reproducible from a stored row rather
 * than depending on whatever the fee/cost tables look like at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_profits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            // Denormalized order date — the grouping key for every report.
            $table->date('placed_on');
            $table->char('base_currency', 3)->default('SAR');

            $table->decimal('gross_revenue_base', 15, 4)->default(0);   // as sold, VAT-inclusive
            $table->decimal('discounts_base', 15, 4)->default(0);       // positive number
            $table->decimal('shipping_revenue_base', 15, 4)->default(0);// ex-VAT
            $table->decimal('net_revenue_base', 15, 4)->default(0);     // ex-VAT, after discounts
            $table->decimal('vat_base', 15, 4)->default(0);             // collected — never profit

            $table->decimal('cogs_base', 15, 4)->default(0);
            $table->decimal('total_fees_base', 15, 4)->default(0);      // excludes tax/discount
            $table->json('fees_by_type')->nullable();
            $table->decimal('ad_spend_base', 15, 4)->default(0);

            $table->decimal('refund_revenue_base', 15, 4)->default(0);
            $table->decimal('refund_cogs_base', 15, 4)->default(0);     // recovered (restocked)
            $table->decimal('lost_cogs_base', 15, 4)->default(0);       // written off

            $table->decimal('net_profit_base', 15, 4)->default(0);
            $table->decimal('margin_pct', 9, 4)->nullable();            // null when net revenue = 0

            $table->boolean('is_estimated')->default(false);
            $table->decimal('estimated_share', 9, 4)->default(0);       // 0..1
            $table->boolean('missing_cost')->default(false);
            $table->unsignedSmallInteger('calc_version')->default(1);   // bump to force recompute
            $table->dateTime('computed_at');
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['organization_id', 'placed_on'], 'px_profit_period');
            $table->index(['organization_id', 'store_id', 'placed_on'], 'px_profit_store');
            $table->index(['organization_id', 'is_estimated'], 'px_profit_estimated');
            $table->index('calc_version', 'px_profit_calcv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_profits');
    }
};
