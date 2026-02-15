<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('midtrans_snap_token')->nullable()->after('payment_id');
            $table->string('midtrans_transaction_id')->nullable()->after('midtrans_snap_token');
            $table->string('midtrans_payment_type')->nullable()->after('midtrans_transaction_id');
            $table->string('midtrans_transaction_status')->nullable()->after('midtrans_payment_type');
            $table->timestamp('midtrans_transaction_time')->nullable()->after('midtrans_transaction_status');
            
            $table->index('midtrans_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['midtrans_transaction_id']);
            $table->dropColumn([
                'midtrans_snap_token',
                'midtrans_transaction_id',
                'midtrans_payment_type',
                'midtrans_transaction_status',
                'midtrans_transaction_time',
            ]);
        });
    }
};