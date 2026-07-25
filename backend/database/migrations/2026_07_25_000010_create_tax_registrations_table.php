<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant's tax registration (spec 05 §4.3) — the legal seller identity printed on every invoice.
 * One per country per organization; KSA requires a 15-digit VAT number and a full national address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_registrations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('country_code', 2)->default('SA');
            $table->string('legal_name', 255);
            $table->string('legal_name_ar', 255)->nullable();
            $table->string('vat_number', 20)->nullable();          // KSA: 15 digits, starts+ends with 3
            $table->string('identification_scheme', 10)->nullable(); // CRN|MOM|MLS|SAG|OTH
            $table->string('identification_value', 50)->nullable();
            $table->string('street', 255)->nullable();
            $table->string('building_number', 10)->nullable();
            $table->string('additional_street', 255)->nullable();
            $table->string('district', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('postal_zone', 10)->nullable();
            $table->string('province', 255)->nullable();
            $table->decimal('default_tax_rate', 5, 2)->default(15.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'country_code']);
            $table->index('vat_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_registrations');
    }
};
