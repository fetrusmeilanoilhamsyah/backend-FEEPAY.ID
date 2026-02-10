<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

class SyncDigiflazz extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'digiflazz:sync {--category=}';

    /**
     * The console command description.
     */
    protected $description = 'Sync products from Digiflazz API to local database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting Digiflazz product sync...');

        try {
            // Get credentials - Modified to have fallback to env() if config is empty
            $username = config('services.digiflazz.username') ?? env('DIGIFLAZZ_USERNAME');
            $apiKey = config('services.digiflazz.api_key') ?? env('DIGIFLAZZ_API_KEY');
            $baseUrl = config('services.digiflazz.base_url') ?? env('DIGIFLAZZ_BASE_URL', 'https://api.digiflazz.com/v1');

            if (!$username || !$apiKey) {
                $this->error('❌ Digiflazz credentials not found! Check your .env file.');
                return 1;
            }

            // Generate signature - Changed 'df' to 'pricelist' for production usage
            $signature = md5($username . $apiKey . 'pricelist');

            // Prepare request payload
            $payload = [
                'cmd' => 'prepaid',
                'username' => $username,
                'sign' => $signature,
            ];

            // Call Digiflazz API
            $this->info('📡 Fetching products from Digiflazz API...');
            
            $response = Http::timeout(60)
                ->post("{$baseUrl}/price-list", $payload);

            if (!$response->successful()) {
                $this->error('❌ API request failed: ' . $response->body());
                return 1;
            }

            $data = $response->json();

            // Check if API returned an error message (like Rate Limit or IP Block)
            if (isset($data['data']['message'])) {
                $this->error('⚠️ Supplier Message: ' . $data['data']['message']);
                return 1;
            }

            if (!isset($data['data']) || !is_array($data['data'])) {
                $this->error('❌ Invalid API response format');
                return 1;
            }

            $products = $data['data'];
            $category = $this->option('category');

            // Filter by category if provided
            if ($category) {
                $products = array_filter($products, function($item) use ($category) {
                    return isset($item['category']) && 
                           strtolower($item['category']) === strtolower($category);
                });
                $this->info("🔍 Filtered to category: {$category}");
            }

            $totalProducts = count($products);
            $this->info("📦 Found {$totalProducts} products to sync");

            if ($totalProducts === 0) {
                $this->warn('📭 No products to sync');
                return 0;
            }

            // Progress bar
            $bar = $this->output->createProgressBar($totalProducts);
            $bar->start();

            $syncedCount = 0;
            $skippedCount = 0;
            $defaultMargin = config('feepay.margin', 2000);

            foreach ($products as $item) {
                try {
                    // Extract data from API response
                    $sku = $item['buyer_sku_code'] ?? null;
                    $productName = $item['product_name'] ?? 'Unknown Product';
                    $cat = $item['category'] ?? 'General';
                    $brand = $item['brand'] ?? null;
                    $modalPrice = $item['price'] ?? 0;
                    
                    // Selling price logic
                    $sellingPrice = $modalPrice + $defaultMargin;
                    
                    $buyerProductStatus = $item['buyer_product_status'] ?? false;
                    $sellerProductStatus = $item['seller_product_status'] ?? false;

                    // Skip if no SKU
                    if (!$sku) {
                        $skippedCount++;
                        $bar->advance();
                        continue;
                    }

                    // Determine status
                    $status = ($buyerProductStatus && $sellerProductStatus) ? 'active' : 'inactive';

                    // Update or create product
                    Product::updateOrCreate(
                        ['sku' => $sku],
                        [
                            'name' => $productName,
                            'category' => $cat,
                            'brand' => $brand,
                            'cost_price' => $modalPrice,
                            'selling_price' => $sellingPrice,
                            'stock' => 'unlimited',
                            'status' => $status,
                        ]
                    );

                    $syncedCount++;

                } catch (\Exception $e) {
                    Log::error('Failed to sync product', [
                        'sku' => $sku ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    $skippedCount++;
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Summary Table
            $this->info("✅ Sync completed successfully!");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Products', $totalProducts],
                    ['Synced', $syncedCount],
                    ['Skipped', $skippedCount],
                ]
            );

            Log::info('Digiflazz sync completed', [
                'total' => $totalProducts,
                'synced' => $syncedCount,
                'skipped' => $skippedCount,
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error('💥 Sync failed: ' . $e->getMessage());
            Log::error('Digiflazz sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }
}