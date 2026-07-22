<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed fee lines per order (spec 01 §3.3).
 *
 * Order-level when `order_item_id` is null, item-level otherwise. This is the table that makes
 * "true net profit" possible — marketplace commission, fulfilment, shipping, payment, storage,
 * advertising and refund costs all land here instead of being invisible.
 *
 * `amount` is SIGNED: positive = cost to the merchant, negative = credit/reimbursement.
 *
 * `organization_id` and `store_id` are denormalized because `orders` carries neither an org id
 * nor a cheap path to one — every aggregate would otherwise need a correlated subquery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_item_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');

            // commission|fulfilment|shipping|payment|refund|storage|advertising|tax|discount|other
            // NOTE: `tax` and `discount` are recorded for reconciliation but EXCLUDED from
            // total_fees — VAT is handled by the VAT model and discounts are already netted out
            // of revenue. Counting them here is the likeliest arithmetic bug in this feature.
            $table->string('type', 24);
            // platform-native label, e.g. fba_pick_pack, referral, cod_handling, rto_shipping
            $table->string('subtype', 64)->nullable();

            $table->decimal('amount', 15, 4)->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->decimal('fx_rate_to_base', 18, 8)->default(1);
            $table->decimal('amount_base', 15, 4)->default(0);

            $table->boolean('is_estimated')->default(false);
            // api|settlement|webhook|raw_data|rule|manual|import
            $table->string('source', 16)->default('manual');
            $table->string('external_id', 191)->nullable();   // marketplace fee/transaction id
            $table->string('settlement_id', 191)->nullable(); // groups fees to a payout
            $table->dateTime('posted_at')->nullable();        // often != order date
            $table->json('raw_data')->nullable();

            // Deterministic idempotency key so a settlement re-import never duplicates:
            // {order_external_id}:{type}:{subtype ?? '-'}:{external_id ?? md5(amount|posted_at)}
            $table->string('fee_key', 191);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'fee_key'], 'ux_fees_key');
            $table->index('order_id', 'px_fees_order');
            $table->index('order_item_id', 'px_fees_item');
            $table->index(['organization_id', 'posted_at'], 'px_fees_posted');
            $table->index(['store_id', 'type'], 'px_fees_store_type');
            $table->index('settlement_id', 'px_fees_settlement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fees');
    }
};
