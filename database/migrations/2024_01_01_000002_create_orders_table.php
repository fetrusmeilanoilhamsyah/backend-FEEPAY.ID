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
            $table->string('order_id')->unique();
            $table->string('sku');
            $table->string('product_name');
            $table->string('target_number');
            $table->string('zone_id')->nullable(); // Untuk Server ID Game
            $table->string('customer_email');
            $table->decimal('total_price', 15, 2);
            $table->enum('status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->string('sn')->nullable();
            
            // Kolom relasi dibuat sebagai angka biasa dulu
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            // Kolom Midtrans Lengkap
            $table->string('midtrans_snap_token')->nullable();
            $table->string('midtrans_transaction_id')->nullable();
            $table->string('midtrans_payment_type')->nullable();
            $table->string('midtrans_transaction_status')->nullable();
            $table->timestamp('midtrans_transaction_time')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status', 'customer_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};