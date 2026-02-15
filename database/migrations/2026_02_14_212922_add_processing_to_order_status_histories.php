<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE order_status_histories MODIFY COLUMN status ENUM('pending', 'processing', 'success', 'failed') NOT NULL DEFAULT 'pending'");
    }

    public function down()
    {
        DB::statement("ALTER TABLE order_status_histories MODIFY COLUMN status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending'");
    }
};