<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'image_url')) {
                $table->string('image_url')->nullable()->after('description');
            }
            if (!Schema::hasColumn('products', 'stock')) {
                $table->integer('stock')->default(0)->after('price');
            }
        });

        Schema::table('platform_products', function (Blueprint $table) {
            if (!Schema::hasColumn('platform_products', 'sync_enabled')) {
                $table->boolean('sync_enabled')->default(true)->after('external_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'stock']);
        });

        Schema::table('platform_products', function (Blueprint $table) {
            $table->dropColumn('sync_enabled');
        });
    }
};
