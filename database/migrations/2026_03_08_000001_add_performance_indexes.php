<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orders table - Optimasi query dashboard dan filtering
        Schema::table('orders', function (Blueprint $table) {
            // Drop index lama yang kurang efektif
            $table->dropIndex(['order_id', 'status', 'customer_email']);
            
            // Tambah index yang lebih spesifik
            $table->index('order_id');
            $table->index(['status', 'created_at']);
            $table->index(['customer_email', 'created_at']);
            $table->index('confirmed_at');
            $table->index('midtrans_transaction_id');
        });

        // Products table - Optimasi query product list
        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'category', 'name']);
        });

        // Order Status Histories - Untuk eager loading
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->index(['order_id', 'created_at']);
        });

        // Users table - Untuk authentication
        Schema::table('users', function (Blueprint $table) {
            $table->index(['email', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['customer_email', 'created_at']);
            $table->dropIndex(['confirmed_at']);
            $table->dropIndex(['midtrans_transaction_id']);
            
            $table->index(['order_id', 'status', 'customer_email']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'category', 'name']);
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email', 'is_active']);
        });
    }
};