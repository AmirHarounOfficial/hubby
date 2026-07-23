<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency ledger (spec 02 §3.3). The unique index IS the idempotency mechanism: claiming a rule
 * application is a plain INSERT, and a 23000 violation means "already applied" → outcome `deduped`.
 * No read-then-write race. SyncOrdersJob re-syncing the same order therefore can't re-fire a rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rule_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->onDelete('cascade');
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('automation_run_id')->nullable(); // no FK — runs are pruned
            $table->timestamp('applied_at')->useCurrent();

            $table->unique(
                ['automation_rule_id', 'subject_type', 'subject_id', 'fingerprint'],
                'automation_apps_unique'
            );
            $table->index(['organization_id', 'applied_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_applications');
    }
};
