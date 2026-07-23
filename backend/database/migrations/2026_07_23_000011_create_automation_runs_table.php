<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit of every rule evaluation (spec 02 §3.2). Denormalised rule name/version so the
 * audit survives a rename or delete, and `facts` + `condition_trace` + `actions_applied` make every
 * decision explainable without a join. Pruned to 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('automation_rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->string('rule_name', 120);
            $table->unsignedInteger('rule_version')->default(1);
            $table->string('trigger', 60);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 120)->nullable();
            $table->uuid('correlation_id');
            $table->string('source', 20);
            $table->string('outcome', 16); // matched|skipped|partial|failed|simulated|deduped
            $table->boolean('matched')->default(false);
            $table->boolean('dry_run')->default(false);
            $table->unsignedTinyInteger('chain_depth')->default(0);
            $table->json('facts')->nullable();
            $table->json('condition_trace')->nullable();
            $table->json('actions_applied')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['automation_rule_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['correlation_id']);
            $table->index(['organization_id', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
