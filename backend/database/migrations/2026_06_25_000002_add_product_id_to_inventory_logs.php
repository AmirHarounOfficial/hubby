<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            // Link a log directly to its product so product-level adjustments
            // (which have no specific variant) are still attributable and listable.
            $table->foreignId('product_id')->nullable()->after('id')
                ->constrained()->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
