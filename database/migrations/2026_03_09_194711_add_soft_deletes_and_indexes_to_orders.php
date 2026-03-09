<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add SoftDeletes
            if (!Schema::hasColumn('orders', 'deleted_at')) {
                $table->softDeletes();
            }
        });
        
        // Drop index lama kalau ada
        DB::statement('ALTER TABLE orders DROP INDEX IF EXISTS orders_order_id_status_customer_email_index');
        
        // Add new indexes
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS orders_order_id_unique ON orders(order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_status_created_at_index ON orders(status, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_customer_email_index ON orders(customer_email)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_confirmed_by_index ON orders(confirmed_by)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_deleted_at_index ON orders(deleted_at)');

        // Products indexes
        DB::statement('ALTER TABLE products DROP INDEX IF EXISTS products_category_brand_status_index');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS products_sku_unique ON products(sku)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_status_category_index ON products(status, category)');
        DB::statement('CREATE INDEX IF NOT EXISTS products_brand_index ON products(brand)');

        // Order status histories
        DB::statement('CREATE INDEX IF NOT EXISTS order_status_histories_order_id_created_at_index ON order_status_histories(order_id, created_at)');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};