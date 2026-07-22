<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The COGS ledger (spec 01 §3.3, §4.3).
 *
 * Every unit of COGS ever recognised — and every reversal — is a row here. This is what makes
 * a margin number auditable: you can always answer "which purchase did this sale's cost come
 * from?".
 *
 * `qty` is negative for reversals rather than deleting rows, so history is immutable.
 * `consumption_key` is deterministic, so re-running a calculation is a no-op instead of
 * double-charging COGS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            // restrict: a layer that has been consumed must never vanish underneath the ledger.
            $table->foreignId('cost_layer_id')->constrained('cost_layers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');

            $table->integer('qty')->default(0);                        // negative for reversals
            $table->decimal('unit_cost_base', 15, 4)->default(0);      // copied from the layer
            $table->decimal('amount_base', 15, 4)->default(0);         // qty * unit_cost_base, signed
            $table->dateTime('consumed_at');
            // sale | refund_restock | refund_writeoff | correction
            $table->string('reason', 24)->default('sale');
            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('cost_layer_consumptions')->nullOnDelete();

            // sale:{order_item_id}:{cost_layer_id}
            // rev:{original_consumption_id}
            // corr:{order_item_id}:{cost_layer_id}:{calc_version}
            $table->string('consumption_key', 191);
            $table->timestamps();

            $table->unique(['organization_id', 'consumption_key'], 'ux_consumption_key');
            $table->index('order_item_id', 'px_consumption_item');
            $table->index('cost_layer_id', 'px_consumption_layer');
            $table->index(['organization_id', 'consumed_at'], 'px_consumption_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_layer_consumptions');
    }
};
