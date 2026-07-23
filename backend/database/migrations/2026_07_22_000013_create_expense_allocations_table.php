<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized daily slices of every expense (spec 01 §3.3), written by ExpenseAmortizer.
 *
 * Reporting sums this table for a period — never the recurrence rules — so a P&L never depends on
 * a date-math loop. The (expense_id, date) unique makes re-amortization idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('expense_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('amount_base', 15, 4)->default(0); // daily slice
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['expense_id', 'date'], 'ux_expense_day');
            $table->index(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_allocations');
    }
};
