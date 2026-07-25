<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Invoice lines (spec 05 §4.7). unit_price is TAX-EXCLUSIVE (BT-146). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('quantity', 15, 4);
            $table->string('unit_code', 10)->default('PCE');
            $table->decimal('unit_price', 15, 4);              // BT-146, tax-exclusive
            $table->decimal('line_extension_amount', 15, 2);   // BT-131
            $table->decimal('allowance_amount', 15, 2)->default(0);
            $table->string('allowance_reason', 255)->nullable();
            $table->char('tax_category', 1)->default('S');     // S|Z|E|O
            $table->decimal('tax_percent', 5, 2)->default(15.00);
            $table->decimal('tax_amount', 15, 2);              // KSA-11
            $table->decimal('line_amount_with_tax', 15, 2);    // KSA-12
            $table->string('tax_exemption_reason_code', 20)->nullable();
            $table->string('tax_exemption_reason', 1000)->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'line_number']);
            $table->index('organization_id');
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
