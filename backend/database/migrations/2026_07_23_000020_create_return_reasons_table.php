<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Return reason taxonomy (spec 03 §3.1). A null organization_id = a global seeded reason visible to
 * every org; a set organization_id = a merchant's own reason.
 *
 * The five `logistics` codes are the RTO mapping targets — carriers report exactly those failure
 * shapes, so an RTO auto-detected from tracking maps straight onto one of them (spec §4.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_reasons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 48);
            $table->string('group', 16)->default('other'); // customer|product|logistics|fraud|other
            $table->string('label_en', 120);
            $table->string('label_ar', 120);
            $table->string('description_en', 255)->nullable();
            $table->string('description_ar', 255)->nullable();
            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('is_defect')->default(false);          // merchant fault ⇒ refund shipping
            $table->boolean('is_customer_fault')->default(false);  // ⇒ restocking fee by default
            $table->string('default_disposition', 24)->default('restock'); // restock|scrap|quarantine|return_to_vendor|repair
            $table->boolean('visible_in_portal')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // NOTE: MySQL treats NULLs as distinct in a composite unique, so this does NOT stop two
            // global reasons sharing a code — the seeder and request validation enforce that.
            $table->unique(['organization_id', 'code'], 'return_reasons_org_code_unique');
            $table->index(['organization_id', 'is_active']);
            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_reasons');
    }
};
