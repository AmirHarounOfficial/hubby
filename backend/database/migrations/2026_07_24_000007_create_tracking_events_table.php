<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only tracking history (spec 04 §3.7). Events arrive out of order (webhooks retry, polls
 * overlap, carriers backfill), so this table is deduped by fingerprint and the shipment's status is
 * always recomputed from the event with the greatest event_at — never "the last one we received".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_package_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('raw_status', 160)->nullable();
            $table->string('raw_code', 40)->nullable();
            $table->string('description_en', 500)->nullable();
            $table->string('description_ar', 500)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('signed_by', 160)->nullable();
            $table->timestamp('event_at');
            $table->timestamp('received_at');
            $table->enum('source', ['webhook', 'poll', 'manual'])->default('poll');
            $table->boolean('is_exception')->default(false);
            $table->string('fingerprint', 64);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'fingerprint']);
            $table->index(['shipment_id', 'event_at']);
            $table->index(['status', 'event_at']);
            $table->index('is_exception');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
