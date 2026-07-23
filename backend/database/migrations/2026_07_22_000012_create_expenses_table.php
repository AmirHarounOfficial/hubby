<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business expenses not attributable to a single order (spec 01 §3.3): software, salaries, rent,
 * marketing, etc. Recurring or one-off, optionally amortized to daily slices in
 * `expense_allocations` so the P&L can subtract operating overhead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name', 191);
            // software|salary|rent|marketing|logistics|packaging|bank|tax|other
            $table->string('category', 24)->default('other');
            $table->string('type', 16)->default('one_off'); // one_off | recurring
            $table->decimal('amount', 15, 2)->default(0);    // charged amount per occurrence
            $table->char('currency', 3)->default('SAR');
            $table->decimal('fx_rate_to_base', 18, 8)->default(1);
            $table->decimal('amount_base', 15, 2)->default(0);
            // daily|weekly|monthly|quarterly|yearly — required when type = recurring
            $table->string('recurrence', 16)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();             // null = open-ended
            $table->boolean('amortize')->default(true);      // false = charge fully on starts_on
            // none|revenue|orders|units — how it splits across channels/SKUs in reports
            $table->string('allocation_method', 16)->default('revenue');
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'starts_on']);
            $table->index(['organization_id', 'category']);
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
