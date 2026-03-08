<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * ✅ PERFORMANCE FIX: Add critical indexes for query optimization
     */
    public function up(): void
    {
        // Orders table indexes
        Schema::table('orders', function (Blueprint $table) {
            // Composite index untuk filtering common queries
            // Digunakan untuk: WHERE user_id = X AND status = Y ORDER BY created_at
            if (!$this->indexExists('orders', 'idx_orders_user_status_date')) {
                $table->index(['user_id', 'status', 'created_at'], 'idx_orders_user_status_date');
            }
            
            // Index untuk lookup by trx_id (sering dicari di callback)
            if (!$this->indexExists('orders', 'idx_orders_trx_id')) {
                $table->index('trx_id', 'idx_orders_trx_id');
            }
            
            // Index untuk webhook callback lookup
            if (!$this->indexExists('orders', 'idx_orders_provider_status')) {
                $table->index(['provider_trx_id', 'status'], 'idx_orders_provider_status');
            }

            // Index untuk status filtering
            if (!$this->indexExists('orders', 'idx_orders_status')) {
                $table->index('status', 'idx_orders_status');
            }

            // Index untuk date range queries
            if (!$this->indexExists('orders', 'idx_orders_created_at')) {
                $table->index('created_at', 'idx_orders_created_at');
            }
        });

        // Products table indexes
        Schema::table('products', function (Blueprint $table) {
            // Index untuk category filtering
            if (!$this->indexExists('products', 'idx_products_category_status')) {
                $table->index(['category', 'status'], 'idx_products_category_status');
            }

            // Index untuk SKU lookup
            if (!$this->indexExists('products', 'idx_products_buyer_sku')) {
                $table->index('buyer_sku_code', 'idx_products_buyer_sku');
            }

            // Index untuk status filtering
            if (!$this->indexExists('products', 'idx_products_status')) {
                $table->index('status', 'idx_products_status');
            }

            // Index untuk brand filtering
            if (!$this->indexExists('products', 'idx_products_brand')) {
                $table->index('brand', 'idx_products_brand');
            }
        });

        // Users table indexes
        Schema::table('users', function (Blueprint $table) {
            // Index untuk email login lookup
            if (!$this->indexExists('users', 'idx_users_email')) {
                $table->index('email', 'idx_users_email');
            }

            // Index untuk phone lookup
            if (!$this->indexExists('users', 'idx_users_phone')) {
                $table->index('phone', 'idx_users_phone');
            }

            // Index untuk created_at (untuk analytics)
            if (!$this->indexExists('users', 'idx_users_created_at')) {
                $table->index('created_at', 'idx_users_created_at');
            }
        });

        // Jika ada table topups
        if (Schema::hasTable('topups')) {
            Schema::table('topups', function (Blueprint $table) {
                if (!$this->indexExists('topups', 'idx_topups_user_status')) {
                    $table->index(['user_id', 'status'], 'idx_topups_user_status');
                }

                if (!$this->indexExists('topups', 'idx_topups_payment_id')) {
                    $table->index('payment_id', 'idx_topups_payment_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_user_status_date');
            $table->dropIndex('idx_orders_trx_id');
            $table->dropIndex('idx_orders_provider_status');
            $table->dropIndex('idx_orders_status');
            $table->dropIndex('idx_orders_created_at');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_category_status');
            $table->dropIndex('idx_products_buyer_sku');
            $table->dropIndex('idx_products_status');
            $table->dropIndex('idx_products_brand');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_email');
            $table->dropIndex('idx_users_phone');
            $table->dropIndex('idx_users_created_at');
        });

        if (Schema::hasTable('topups')) {
            Schema::table('topups', function (Blueprint $table) {
                $table->dropIndex('idx_topups_user_status');
                $table->dropIndex('idx_topups_payment_id');
            });
        }
    }

    /**
     * Check if index exists
     */
    private function indexExists(string $table, string $index): bool
    {
        $indexes = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->listTableIndexes($table);

        return isset($indexes[$index]);
    }
};