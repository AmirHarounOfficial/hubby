<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily FX rates (spec 01 §3.3, §4.5).
 *
 * Read as: 1 unit of `quote` = `rate` units of `base`.
 * Rates are snapshotted onto cost/fee rows at write time — a historical margin must never
 * change because today's exchange rate moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->date('date');
            $table->decimal('rate', 18, 8);
            $table->string('source', 32)->default('manual'); // manual | ecb | openexchange
            $table->timestamps();

            $table->unique(['base', 'quote', 'date'], 'ux_fx_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};
