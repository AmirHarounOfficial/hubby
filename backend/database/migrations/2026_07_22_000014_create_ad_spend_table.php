<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising spend per channel / campaign / day (spec 01 §3.3). Subtracted from the period P&L as
 * the "Advertising" line. Manual + CSV in v1; the Ads API is a future SyncAdSpendJob.
 *
 * Table name pinned to `ad_spend` (Laravel would otherwise pluralise the model to `ad_spends`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spend', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->nullable()->constrained()->onDelete('cascade');
            // amazon_ads|noon_ads|salla_ads|trendyol_ads|meta|google|tiktok|snapchat|other
            $table->string('channel', 32);
            $table->string('campaign_name', 191)->nullable();
            $table->string('campaign_external_id', 191)->nullable();
            $table->string('sku', 191)->nullable(); // set only when the channel attributes to SKU
            $table->date('date');
            $table->decimal('spend', 15, 4)->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->decimal('fx_rate_to_base', 18, 8)->default(1);
            $table->decimal('spend_base', 15, 4)->default(0);
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->integer('orders_attributed')->nullable();
            $table->decimal('sales_attributed', 15, 4)->nullable(); // base currency
            $table->string('source', 16)->default('manual'); // manual | csv | api
            $table->char('spend_key', 64); // sha1(channel|campaign_external_id|sku|date|store_id)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'spend_key'], 'ux_adspend_key');
            $table->index(['organization_id', 'date']);
            $table->index(['organization_id', 'channel', 'date']);
            $table->index(['organization_id', 'sku', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend');
    }
};
