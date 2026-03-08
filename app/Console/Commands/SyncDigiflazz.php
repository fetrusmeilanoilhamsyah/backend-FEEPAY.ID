<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncDigiflazz extends Command
{
    protected $signature   = 'digiflazz:sync {--category= : Filter berdasarkan kategori}';
    protected $description = 'Sinkronisasi produk dari Digiflazz API ke database lokal dengan proteksi harga.';

    public function handle(DigiflazzService $digiflazzService): int
    {
        $this->info('🚀 Memulai sinkronisasi Digiflazz...');

        $username = config('services.digiflazz.username');
        $apiKey   = config('services.digiflazz.api_key');

        if (!$username || !$apiKey) {
            $this->error('❌ Kredensial Digiflazz belum dikonfigurasi di .env');
            return self::FAILURE;
        }

        $category = $this->option('category');
        $response = $digiflazzService->getPriceList($category ?: null);

        if (!$response['success']) {
            $this->error('❌ Gagal ambil data dari API: ' . $response['message']);
            return self::FAILURE;
        }

        $products      = $response['data'];
        $totalProducts = count($products);

        if ($totalProducts === 0) {
            $this->warn('📭 Tidak ada produk untuk disinkronkan.');
            return self::SUCCESS;
        }

        $this->info("📦 Ditemukan {$totalProducts} produk.");

        $bar           = $this->output->createProgressBar($totalProducts);
        $syncedCount   = 0;
        $skippedCount  = 0;
        $defaultMargin = (float) config('feepay.margin', 2000);

        $bar->start();

        try {
            DB::transaction(function () use ($products, $defaultMargin, &$syncedCount, &$skippedCount, $bar) {
                foreach ($products as $item) {
                    try {
                        $sku = $item['buyer_sku_code'] ?? null;
                        if (!$sku) {
                            $skippedCount++;
                            $bar->advance();
                            continue;
                        }

                        $costPrice = (float) ($item['price'] ?? 0);
                        $status    = ($item['buyer_product_status'] ?? false) && ($item['seller_product_status'] ?? false)
                            ? 'active'
                            : 'inactive';

                        $existing = Product::where('sku', $sku)->first();

                        if ($existing) {
                            $sellingPrice = (float) $existing->selling_price;
                            if ($costPrice >= $sellingPrice) {
                                $sellingPrice = $costPrice + $defaultMargin;
                            }
                        } else {
                            $sellingPrice = $costPrice + $defaultMargin;
                        }

                        Product::updateOrCreate(
                            ['sku' => $sku],
                            [
                                'name'          => $item['product_name'] ?? 'Unknown',
                                'category'      => $item['category']     ?? 'General',
                                'brand'         => $item['brand']        ?? null,
                                'cost_price'    => $costPrice,
                                'selling_price' => $sellingPrice,
                                'status'        => $status,
                            ]
                        );

                        $syncedCount++;

                    } catch (\Exception $e) {
                        Log::error('Gagal sync produk', ['sku' => $sku ?? 'unknown', 'error' => $e->getMessage()]);
                        $skippedCount++;
                    }

                    $bar->advance();
                }
            });

        } catch (\Exception $e) {
            $this->error('💥 Sync gagal: ' . $e->getMessage());
            Log::error('SyncDigiflazz command gagal', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('✅ Sinkronisasi selesai!');
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Total Produk', $totalProducts],
                ['Berhasil Disync', $syncedCount],
                ['Dilewati', $skippedCount],
            ]
        );

        Log::info('Digiflazz sync selesai', [
            'total'   => $totalProducts,
            'synced'  => $syncedCount,
            'skipped' => $skippedCount,
        ]);

        return self::SUCCESS;
    }
}
