<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    protected $digiflazz;

    public function __construct(DigiflazzService $digiflazz)
    {
        $this->digiflazz = $digiflazz;
    }

    /**
     * Display a listing of products
     * 
     * ✅ PERFORMANCE FIX: Added caching
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'category' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $cacheKey = 'products:list:' . md5(json_encode($validated));

        // ✅ Cache untuk 10 menit
        $products = Cache::remember($cacheKey, 600, function () use ($validated) {
            $query = Product::query()
                ->select([
                    'id', 
                    'code', 
                    'buyer_sku_code',
                    'name', 
                    'category', 
                    'brand',
                    'type',
                    'price', 
                    'status', 
                    'description',
                    'unlimited_stock',
                    'stock'
                ])
                ->where('status', 'active');

            // ✅ SAFE: Parameter binding otomatis oleh Eloquent
            if (!empty($validated['category'])) {
                $query->where('category', $validated['category']);
            }

            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }

            if (!empty($validated['search'])) {
                $query->where(function ($q) use ($validated) {
                    $q->where('name', 'LIKE', "%{$validated['search']}%")
                      ->orWhere('code', 'LIKE', "%{$validated['search']}%")
                      ->orWhere('buyer_sku_code', 'LIKE', "%{$validated['search']}%");
                });
            }

            return $query->orderBy('category')
                ->orderBy('name')
                ->paginate($validated['per_page'] ?? 20);
        });

        return response()->json($products);
    }

    /**
     * Display a specific product
     */
    public function show(string $id)
    {
        $cacheKey = "product:detail:{$id}";

        $product = Cache::remember($cacheKey, 600, function () use ($id) {
            return Product::where('id', $id)
                ->orWhere('code', $id)
                ->orWhere('buyer_sku_code', $id)
                ->where('status', 'active')
                ->firstOrFail();
        });

        return response()->json(['data' => $product]);
    }

    /**
     * Get products by category
     */
    public function byCategory(string $category)
    {
        $cacheKey = "products:category:{$category}";

        $products = Cache::remember($cacheKey, 600, function () use ($category) {
            return Product::where('category', $category)
                ->where('status', 'active')
                ->select([
                    'id', 
                    'code', 
                    'buyer_sku_code',
                    'name', 
                    'category', 
                    'brand',
                    'price', 
                    'status',
                    'description'
                ])
                ->orderBy('name')
                ->get();
        });

        return response()->json(['data' => $products]);
    }

    /**
     * Get all categories
     */
    public function categories()
    {
        $cacheKey = 'products:categories';

        $categories = Cache::remember($cacheKey, 1800, function () {
            return Product::where('status', 'active')
                ->select('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category');
        });

        return response()->json(['data' => $categories]);
    }

    /**
     * Sync products from Digiflazz
     * Untuk admin only
     * 
     * ✅ PERFORMANCE FIX: Batch upsert untuk efisiensi
     */
    public function sync(Request $request)
    {
        try {
            Log::info('Product sync initiated by admin', [
                'user_id' => auth()->id()
            ]);

            $products = $this->digiflazz->getPriceList(true); // Force refresh

            if (empty($products)) {
                return response()->json([
                    'message' => 'No products retrieved from Digiflazz'
                ], 400);
            }

            $syncedCount = 0;
            $batchSize = 100;
            $batches = array_chunk($products, $batchSize);

            foreach ($batches as $batch) {
                $upsertData = [];

                foreach ($batch as $product) {
                    $upsertData[] = [
                        'code' => $product['buyer_sku_code'],
                        'buyer_sku_code' => $product['buyer_sku_code'],
                        'name' => $product['product_name'],
                        'category' => $product['category'],
                        'brand' => $product['brand'],
                        'type' => $product['type'],
                        'price' => $product['price'],
                        'status' => $product['seller_product_status'] === true ? 'active' : 'inactive',
                        'description' => $product['desc'] ?? null,
                        'seller_name' => $product['seller_name'] ?? null,
                        'unlimited_stock' => $product['unlimited_stock'] ?? true,
                        'stock' => $product['stock'] ?? 0,
                        'multi' => $product['multi'] ?? false,
                        'start_cut_off' => $product['start_cut_off'] ?? null,
                        'end_cut_off' => $product['end_cut_off'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // ✅ PERFORMANCE: Batch upsert
                DB::table('products')->upsert(
                    $upsertData,
                    ['buyer_sku_code'], // Unique key
                    [
                        'name', 'category', 'brand', 'type', 'price', 
                        'status', 'description', 'seller_name', 
                        'unlimited_stock', 'stock', 'multi', 
                        'start_cut_off', 'end_cut_off', 'updated_at'
                    ] // Update these columns
                );

                $syncedCount += count($batch);
            }

            // Clear all product caches
            $this->clearProductCaches();

            Log::info('Product sync completed', [
                'total' => $syncedCount,
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Products synced successfully',
                'total' => $syncedCount
            ]);

        } catch (\Exception $e) {
            Log::error('Product sync failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);

            return response()->json([
                'message' => 'Sync failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear all product-related caches
     */
    private function clearProductCaches(): void
    {
        // Clear list caches
        Cache::flush(); // Atau bisa lebih spesifik dengan tags jika pakai Redis
        
        // Atau jika ingin lebih spesifik:
        // Cache::tags(['products'])->flush();
    }

    /**
     * Update product status (admin only)
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive'
        ]);

        $product = Product::findOrFail($id);
        $product->status = $validated['status'];
        $product->save();

        // Clear cache
        Cache::forget("product:detail:{$id}");
        Cache::forget("products:category:{$product->category}");

        Log::info('Product status updated', [
            'product_id' => $id,
            'status' => $validated['status'],
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'message' => 'Product status updated',
            'data' => $product
        ]);
    }

    /**
     * Bulk update product status (admin only)
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'status' => 'required|in:active,inactive'
        ]);

        $updated = Product::whereIn('id', $validated['product_ids'])
            ->update(['status' => $validated['status']]);

        // Clear all caches
        $this->clearProductCaches();

        Log::info('Bulk product status update', [
            'count' => $updated,
            'status' => $validated['status'],
            'user_id' => auth()->id()
        ]);

        return response()->json([
            'message' => 'Products updated successfully',
            'updated_count' => $updated
        ]);
    }
}