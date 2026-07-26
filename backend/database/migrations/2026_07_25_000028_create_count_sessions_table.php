<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cycle counting (spec 08 §3.7/§4.4).
 *
 * `blind` mode exists so the operator counts what is on the shelf rather than what the system says
 * should be there — so expected_qty is never serialised to a non-supervisor device (see CountService).
 * counted_qty is ABSOLUTE, never a delta: the client sends a running total with a monotonic
 * client_seq and the highest one wins, which makes replays idempotent by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 24);
            $table->string('mode', 12)->default('blind');        // blind|informed
            $table->string('scope_type', 16)->default('sku_list'); // full|location|category|sku_list
            $table->json('scope_ref')->nullable();
            $table->string('status', 24)->default('draft');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expected_snapshot_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedInteger('lines_total')->default(0);
            $table->unsignedInteger('lines_counted')->default(0);
            $table->unsignedInteger('lines_variant')->default(0);
            $table->integer('variance_units')->default(0);
            $table->decimal('variance_value', 15, 2)->default(0);
            $table->uuid('applied_log_batch')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
        });

        Schema::create('count_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 120)->nullable();
            $table->string('name', 255)->nullable();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('expected_qty')->nullable();       // frozen at snapshot; never sent when blind
            $table->integer('counted_qty')->default(0);        // ABSOLUTE, not a delta
            $table->integer('live_qty_at_approval')->nullable(); // the actual apply base
            $table->integer('variance')->nullable();
            $table->string('status', 16)->default('counted');
            $table->foreignId('recount_of_entry_id')->nullable()->constrained('count_entries')->nullOnDelete();
            $table->foreignId('counted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('client_seq')->nullable(); // highest wins — never summed
            $table->timestamp('client_counted_at')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['count_session_id', 'status']);
            $table->unique(['count_session_id', 'product_variant_id', 'stock_location_id'], 'count_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_entries');
        Schema::dropIfExists('count_sessions');
    }
};
