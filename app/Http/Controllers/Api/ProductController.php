<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DigiflazzService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            
            // ✅ PERBAIKAN: Cache product list selama 10 menit
            $cacheKey = 'products:list:' . ($category ?? 'all');
            
            $products = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($category) {
                $query = Product::active();

                if ($category) {
                    $query->where('category', $category);
                }

                return $query->orderBy('category')->orderBy('name')->get();
            });

            return response()->json(['success' => true, 'data' => $products], 200);

        } catch (Exception $e) {
            Log::error('ProductController::index gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil data produk.'], 500);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'selling_price' => 'required|numeric|min:0|max:10000000',
        ]);

        try {
            $product = Product::findOrFail($id);

            $product->update([
                'selling_price' => (float) $request->selling_price,
            ]);

            // ✅ PERBAIKAN: Clear cache setelah update
            $this->clearProductCache();

            return response()->json([
                'success' => true,
                'message' => 'Harga berhasil diperbarui.',
                'data'    => $product->fresh(),
            ]);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan.'], 404);
        }
    }

    public function bulkUpdateMargin(Request $request): JsonResponse
    {
        $request->validate([
            'margin' => 'required|numeric|min:0|max:1000000',
        ]);

        try {
            $margin = (float) $request->margin;

            DB::statement('UPDATE products SET selling_price = cost_price + ?', [$margin]);

            $updatedCount = Product::count();

            // ✅ PERBAIKAN: Clear cache setelah bulk update
            $this->clearProductCache();

            return response()->json([
                'success' => true,
                'message' => 'Margin Rp ' . number_format($margin, 0, ',', '.') . " diterapkan ke {$updatedCount} produk.",
                'data'    => ['updated_count' => $updatedCount],
            ], 200);

        } catch (Exception $e) {
            Log::error('ProductController::bulkUpdateMargin gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui margin.'], 500);
        }
    }

    public function sync(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            $response = $this->digiflazzService->getPriceList($category);

            if (!$response['success']) {
                return response()->json(['success' => false, 'message' => $response['message']], 400);
            }

            $defaultMargin = (float) config('feepay.margin', 2000);
            $syncedCount   = 0;

            // ✅ PERBAIKAN: Batch upsert untuk performa
            DB::transaction(function () use ($response, $defaultMargin, &$syncedCount) {
                $batchSize = 100;
                $batch = [];
                $existingSKUs = Product::pluck('selling_price', 'sku')->toArray();
                
                foreach ($response['data'] as $item) {
                    $sku = $item['buyer_sku_code'] ?? null;
                    if (!$sku) continue;

                    $costPrice = (float) ($item['price'] ?? 0);
                    $status    = ($item['buyer_product_status'] ?? false) && ($item['seller_product_status'] ?? false)
                        ? 'active'
                        : 'inactive';

                    // Preserve custom selling price
                    if (isset($existingSKUs[$sku])) {
                        $currentSellingPrice = (float) $existingSKUs[$sku];
                        $sellingPrice = $costPrice >= $currentSellingPrice 
                            ? $costPrice + $defaultMargin 
                            : $currentSellingPrice;
                    } else {
                        $sellingPrice = $costPrice + $defaultMargin;
                    }

                    $batch[] = [
                        'sku'           => $sku,
                        'name'          => $item['product_name'] ?? 'Unknown',
                        'category'      => $item['category'] ?? 'General',
                        'brand'         => $item['brand'] ?? null,
                        'cost_price'    => $costPrice,
                        'selling_price' => $sellingPrice,
                        'status'        => $status,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];

                    $syncedCount++;

                    if (count($batch) >= $batchSize) {
                        Product::upsert(
                            $batch,
                            ['sku'],
                            ['name', 'category', 'brand', 'cost_price', 'selling_price', 'status', 'updated_at']
                        );
                        $batch = [];
                    }
                }

                if (!empty($batch)) {
                    Product::upsert(
                        $batch,
                        ['sku'],
                        ['name', 'category', 'brand', 'cost_price', 'selling_price', 'status', 'updated_at']
                    );
                }
            });

            // ✅ PERBAIKAN: Clear cache setelah sync
            $this->clearProductCache();

            return response()->json([
                'success' => true,
                'message' => "Sinkronisasi {$syncedCount} produk berhasil.",
                'data'    => ['synced_count' => $syncedCount],
            ], 200);

        } catch (Exception $e) {
            Log::error('ProductController::sync gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal sinkronisasi: ' . $e->getMessage()], 500);
        }
    }

    // ✅ PERBAIKAN: Helper untuk clear product cache
    private function clearProductCache(): void
    {
        Cache::forget('products:list:all');
        Cache::forget('dashboard:product_stats');
        Cache::forget('dashboard:total_products');
        
        foreach (Product::distinct()->pluck('category') as $cat) {
            Cache::forget('products:list:' . $cat);
        }
    }
}