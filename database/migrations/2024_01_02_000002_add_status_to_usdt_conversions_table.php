<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usdt_conversions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])
                  ->default('pending')
                  ->after('proof_path');
            $table->text('admin_note')->nullable()->after('status');
            
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('usdt_conversions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'admin_note']);
        });
    }
};