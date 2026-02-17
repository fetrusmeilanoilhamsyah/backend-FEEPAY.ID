<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // ✅ Update table ORDERS (bukan cuma order_status_histories)
        DB::statement("
            ALTER TABLE orders 
            MODIFY COLUMN status 
            ENUM('pending', 'processing', 'success', 'failed') 
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down()
    {
        // Rollback ke enum lama
        DB::statement("
            ALTER TABLE orders 
            MODIFY COLUMN status 
            ENUM('pending', 'success', 'failed') 
            NOT NULL DEFAULT 'pending'
        ");
    }
};