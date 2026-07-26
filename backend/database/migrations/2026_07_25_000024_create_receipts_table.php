<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound receipts (spec 08 §3.6). `expected_lines` drives INFORMED receiving (expected vs received,
 * discrepancies route to supervisor review); its absence means BLIND receiving, where unexpected SKUs
 * are normal and the receipt completes straight away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 24);
            $table->string('type', 16)->default('inbound');   // inbound|return|transfer
            $table->string('status', 24)->default('draft');
            $table->string('supplier_name', 180)->nullable();
            $table->string('reference', 64)->nullable();      // PO / ASN / tracking
            $table->json('expected_lines')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
