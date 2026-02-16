<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Support\Facades\Log;

class SyncProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-sync produk dari Digiflazz API';

    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Memulai sync produk dari Digiflazz...');

        try {
            $response = $this->digiflazzService->getPriceList();

            if (!$response['success']) {
                $this->error('❌ Gagal ambil data dari Digiflazz: ' . $response['message']);
                Log::error('Auto sync failed', ['message' => $response['message']]);
                return 1;
            }

            $margin = config('feepay.margin', 1000);
            $syncedCount = 0;

            foreach ($response['data'] as $item) {
                $costPrice = $item['price'] ?? 0;

                $existingProduct = Product::where('sku', $item['buyer_sku_code'])->first();

                // Kalau produk sudah ada, jangan timpa harga jual yang sudah diedit admin
                $sellingPrice = $existingProduct
                    ? $existingProduct->selling_price
                    : ($costPrice + $margin);

                Product::updateOrCreate(
                    ['sku' => $item['buyer_sku_code']],
                    [
                        'name'         => $item['product_name'] ?? 'Unknown',
                        'category'     => $item['category'] ?? 'General',
                        'cost_price'   => $costPrice,
                        'selling_price' => $sellingPrice,
                    ]
                );

                $syncedCount++;
            }

            $this->info("✅ Berhasil sync {$syncedCount} produk.");

            Log::info('Auto sync products success', [
                'synced_count' => $syncedCount,
                'time' => now()->toIso8601String(),
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            Log::error('Auto sync exception', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}