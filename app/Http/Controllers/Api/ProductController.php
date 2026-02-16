<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class ProductController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * Get all products (with optional category filter)
     */
    public function index(Request $request)
    {
        try {
            $category = $request->query('category');

            $query = Product::query();

            if ($category) {
                $query->where('category', $category);
            }

            $products = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $products,
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch products', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to fetch products'], 500);
        }
    }

    /**
     * Update Product Price (Admin Only)
     */
    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'selling_price' => 'required|numeric|min:0'
            ]);

            $product = Product::find($id);
            
            if (!$product) {
                return response()->json(['success' => false, 'message' => 'Product not found'], 404);
            }

            $product->update([
                'selling_price' => $request->selling_price
            ]);

            Log::info('Product price updated manually', [
                'product_id' => $id,
                'new_price' => $request->selling_price
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Price updated successfully',
                'data' => $product
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk update margin for all products (Admin Only)
     * POST /api/admin/{path}/products/bulk-margin
     */
    public function bulkUpdateMargin(Request $request)
    {
        try {
            $request->validate([
                'margin' => 'required|numeric|min:0',
            ]);

            $margin = $request->margin;
            $updatedCount = 0;

            $products = Product::all();

            foreach ($products as $product) {
                $product->update([
                    'selling_price' => $product->cost_price + $margin
                ]);
                $updatedCount++;
            }

            Log::info('Bulk margin updated', [
                'margin' => $margin,
                'updated_count' => $updatedCount,
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Margin Rp " . number_format($margin, 0, ',', '.') . " berhasil diterapkan ke {$updatedCount} produk",
                'data' => ['updated_count' => $updatedCount, 'margin' => $margin],
            ], 200);

        } catch (Exception $e) {
            Log::error('Bulk margin update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Sync products from Digiflazz API (Admin only)
     */
    public function sync(Request $request)
    {
        try {
            $category = $request->query('category');
            $response = $this->digiflazzService->getPriceList($category);

            if (!$response['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to sync products: ' . $response['message'],
                ], 400);
            }

            $margin = config('feepay.margin', 1000);
            $syncedCount = 0;

            foreach ($response['data'] as $item) {
                $costPrice = $item['price'] ?? 0;
                
                $existingProduct = Product::where('sku', $item['buyer_sku_code'])->first();
                
                $sellingPrice = $existingProduct ? $existingProduct->selling_price : ($costPrice + $margin);

                Product::updateOrCreate(
                    ['sku' => $item['buyer_sku_code']],
                    [
                        'name' => $item['product_name'] ?? 'Unknown',
                        'category' => $item['category'] ?? 'General',
                        'cost_price' => $costPrice,
                        'selling_price' => $sellingPrice,
                    ]
                );

                $syncedCount++;
            }

            return response()->json([
                'success' => true,
                'message' => "Successfully synced {$syncedCount} products",
                'data' => ['synced_count' => $syncedCount],
            ], 200);

        } catch (Exception $e) {
            Log::error('Product sync failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}