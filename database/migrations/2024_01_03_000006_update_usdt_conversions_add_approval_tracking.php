<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usdt_conversions', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('admin_note')->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('customer_email')->nullable()->after('bank_details');
            $table->string('customer_phone')->nullable()->after('customer_email');

            $table->index('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('usdt_conversions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at', 'customer_email', 'customer_phone']);
        });
    }
};