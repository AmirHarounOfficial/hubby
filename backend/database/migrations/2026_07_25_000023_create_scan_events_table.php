<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * scan_events (spec 08 §3.8) — the audit + idempotency spine. EVERY scan lands here: accepted,
 * rejected, online or replayed from offline.
 *
 * `uuid` is client-generated and is the single most important column: a warehouse phone loses signal
 * constantly, so the app queues scans and replays them, and the same scan may arrive several times.
 * The unique (organization_id, uuid) guard turns a replay into an idempotent no-op that returns the
 * ORIGINAL response verbatim from `response` — the operator sees the same answer either way.
 *
 * session_id is an FK-by-convention (no DB constraint) so there is one hot insert path regardless of
 * session type; this table is the highest-volume one in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 64)->nullable();
            $table->string('session_type', 16);           // pick|pack|receive|count|lookup
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('target_type', 24)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('action', 24)->default('scan');
            $table->string('barcode', 128)->nullable();
            $table->string('barcode_raw', 160)->nullable();
            $table->string('symbology', 24)->nullable();
            $table->string('input_method', 12)->default('camera'); // camera|hid|manual
            $table->foreignId('resolved_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('resolved_product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty')->default(1);
            $table->string('result', 24);                 // accepted|duplicate|unknown_barcode|…
            $table->string('reject_reason', 64)->nullable();
            $table->boolean('was_offline')->default(false);
            $table->timestamp('client_scanned_at', 3)->nullable();
            $table->unsignedBigInteger('client_seq')->nullable(); // real ordering key per device
            $table->timestamp('received_at', 3)->nullable();
            $table->json('response')->nullable();          // replayed verbatim on duplicate
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'uuid']);   // the idempotency guard
            $table->index(['organization_id', 'session_type', 'session_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_events');
    }
};
