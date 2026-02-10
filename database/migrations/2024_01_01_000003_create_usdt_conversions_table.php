<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usdt_conversions', function (Blueprint $table) {
            $table->id();
            $table->string('trx_id')->unique();
            $table->decimal('amount', 15, 2);
            $table->enum('network', ['TRC20', 'ERC20', 'BEP20', 'APTOS']);
            $table->decimal('idr_received', 15, 2);
            $table->json('bank_details');
            $table->string('proof_path')->nullable();
            $table->timestamps();

            $table->index('trx_id');
            $table->index('network');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usdt_conversions');
    }
};