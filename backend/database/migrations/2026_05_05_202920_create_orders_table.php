<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->string('external_id');
            $table->string('status');
            $table->decimal('total', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
