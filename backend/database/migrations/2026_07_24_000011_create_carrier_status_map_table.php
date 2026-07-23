<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-driven carrier→normalized status mapping (spec 04 §3.11) so a new carrier status code doesn't
 * need a deploy. An unmapped status falls back to `exception` and raises a warning, so new codes are
 * discovered rather than silently mis-rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_status_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('carrier_code', 32);
            $table->string('raw_code', 64)->nullable();
            $table->string('raw_status', 160)->nullable();
            $table->string('normalized_status', 32);
            $table->boolean('is_exception')->default(false);
            $table->boolean('is_final')->default(false);
            $table->string('description_en', 255)->nullable();
            $table->string('description_ar', 255)->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamps();

            $table->index(['carrier_code', 'raw_code']);
            $table->index(['carrier_code', 'raw_status']);
            $table->unique(['carrier_code', 'raw_code', 'raw_status'], 'carrier_status_map_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_status_map');
    }
};
