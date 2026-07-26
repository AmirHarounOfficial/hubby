<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pack sessions (spec 08 §3.5). Multi-box orders get several sessions, one per package_index. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pick_list_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 24);
            $table->string('status', 24)->default('open');
            $table->unsignedTinyInteger('package_index')->default(1);
            $table->unsignedTinyInteger('package_count')->default(1);
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('packaging_type', 32)->nullable();
            $table->string('shipment_ref', 64)->nullable();   // owned by the Shipping module
            $table->string('label_url', 512)->nullable();
            $table->foreignId('packed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['order_id', 'package_index']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('pack_session_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('pack_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 120)->nullable();
            $table->string('name', 255)->nullable();
            $table->unsignedInteger('qty_required');
            $table->unsignedInteger('qty_packed')->default(0);
            $table->timestamps();

            $table->index('pack_session_id');
            $table->unique(['pack_session_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_session_items');
        Schema::dropIfExists('pack_sessions');
    }
};
