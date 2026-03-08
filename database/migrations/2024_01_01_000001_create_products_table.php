<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('brand')->nullable(); // Penting untuk filter Game
            $table->decimal('cost_price', 15, 2);
            $table->decimal('selling_price', 15, 2);
            $table->string('status')->default('active');
            $table->string('stock')->default('unlimited');
            $table->string('type')->default('standard'); // Untuk bedakan UI Game/Pulsa
            $table->timestamps();

            $table->index(['category', 'brand', 'status']);
            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};