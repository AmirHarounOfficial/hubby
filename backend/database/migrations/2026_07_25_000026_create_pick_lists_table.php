<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pick lists (spec 08 §3.4). type=order is one order; type=batch aggregates n orders by SKU. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_lists', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 24);
            $table->string('type', 16)->default('order');   // order|batch|wave
            $table->string('status', 24)->default('draft');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('priority')->default(5);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('picked_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status', 'priority']);
            $table->index(['assigned_user_id', 'status']);
        });

        Schema::create('pick_list_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['pick_list_id', 'order_id']);
            $table->index('order_id');
        });

        Schema::create('pick_list_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('pick_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku', 120)->nullable();      // snapshot — survives catalogue edits
            $table->string('name', 255)->nullable();
            $table->string('image_url', 512)->nullable();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('qty_required');
            $table->unsignedInteger('qty_picked')->default(0);
            $table->unsignedInteger('qty_short')->default(0);
            $table->string('status', 16)->default('pending');
            $table->string('short_reason', 32)->nullable();
            $table->foreignId('substituted_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->foreignId('picked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();

            $table->index(['pick_list_id', 'sequence']);
            $table->index(['pick_list_id', 'status']);
            $table->index('product_variant_id');
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_list_items');
        Schema::dropIfExists('pick_list_orders');
        Schema::dropIfExists('pick_lists');
    }
};
