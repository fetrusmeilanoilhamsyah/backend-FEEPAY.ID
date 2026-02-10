<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sku')->after('order_id');
            $table->string('product_name')->after('sku');
            $table->foreignId('payment_id')->nullable()->after('total_price')->constrained()->onDelete('set null');
            $table->foreignId('confirmed_by')->nullable()->after('sn')->constrained('users')->onDelete('set null');
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');

            $table->index('sku');
            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropIndex(['sku']);
            $table->dropIndex(['payment_id']);
            $table->dropColumn(['sku', 'product_name', 'payment_id', 'confirmed_by', 'confirmed_at']);
        });
    }
};