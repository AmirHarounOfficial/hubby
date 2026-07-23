<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation rules (spec 02 §3.1) — Linnworks' crown-jewel feature, ungated in every plan.
 *
 * `trigger` is a string, not a DB enum, so reserving a new trigger later needs no migration.
 * New rules default to disabled + dry_run: automation never fires until a human turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('trigger', 60);
            $table->json('conditions');
            $table->json('actions');
            $table->unsignedSmallInteger('priority')->default(100); // lower runs first
            $table->boolean('enabled')->default(false);             // safe default: off
            $table->string('run_mode', 10)->default('dry_run');     // live | dry_run
            $table->boolean('stop_processing')->default(false);
            $table->unsignedInteger('version')->default(1);         // part of the idempotency fingerprint
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->unsignedBigInteger('applied_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The hot dispatch query in RuleRepository::forTrigger().
            $table->index(['organization_id', 'trigger', 'enabled', 'priority'], 'automation_rules_dispatch_idx');
            $table->index(['organization_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
