<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Tugas file ini CUMA menyambungkan relasi agar tidak errno 150
            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('set null');
            $table->foreign('confirmed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['confirmed_by']);
        });
    }
};