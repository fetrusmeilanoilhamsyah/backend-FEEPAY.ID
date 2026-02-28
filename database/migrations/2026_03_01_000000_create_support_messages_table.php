<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->string('user_email')->nullable();
            $table->string('user_name')->nullable();
            $table->text('message');
            $table->enum('platform', ['whatsapp', 'telegram'])->default('whatsapp');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('order_id')->nullable();
            $table->timestamps();
            
            $table->index('user_email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};