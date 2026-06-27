<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // The storefront URL / marketplace identifier the merchant connects.
            // Integrations (e.g. Shopify) call the platform API against this.
            if (! Schema::hasColumn('stores', 'domain')) {
                $table->string('domain')->nullable()->after('name');
            }
            // When this store last completed a sync — surfaced on the Stores screen.
            if (! Schema::hasColumn('stores', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('is_master');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['domain', 'last_synced_at']);
        });
    }
};
