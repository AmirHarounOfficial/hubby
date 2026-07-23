<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of everything that moved an RMA (spec 03 §3.4): every status change, refund
 * attempt, restock, and carrier update. Written in the same transaction as the status write, so the
 * audit never lags the state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('actor_type', 16)->default('system'); // user|system|platform|carrier|customer
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label', 120)->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['return_request_id', 'created_at']);
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_events');
    }
};
