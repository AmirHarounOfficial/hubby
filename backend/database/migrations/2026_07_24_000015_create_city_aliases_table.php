<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * City spelling variants → canonical key (spec 04 §4.8). The single highest-value piece of local
 * logic in the shipping spec: Gulf carriers reject or mis-route on unrecognised city strings, and
 * Saudi/UAE address data is spelled a dozen ways (الرياض / Riyadh / Riyad / Ar Riyad → riyadh).
 * organization_id is nullable: null rows are the global seed, org rows are per-merchant extensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_aliases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('country_code', 2)->default('SA');
            $table->string('alias', 160);            // normalized (lowercased, diacritics stripped) input variant
            $table->string('canonical', 120);        // canonical key, e.g. 'riyadh'
            $table->string('canonical_en', 120)->nullable();
            $table->string('canonical_ar', 120)->nullable();
            $table->timestamps();

            $table->index(['country_code', 'alias']);
            $table->index(['country_code', 'canonical']);
            $table->unique(['organization_id', 'country_code', 'alias'], 'city_alias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_aliases');
    }
};
