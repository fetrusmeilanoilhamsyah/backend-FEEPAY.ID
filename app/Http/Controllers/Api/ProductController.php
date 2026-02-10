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
     * [BARU] Update Product Price (Admin Only)
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

            // Margin default 1000 jika tidak ada di config
            $margin = config('feepay.margin', 1000);
            $syncedCount = 0;

            foreach ($response['data'] as $item) {
                $costPrice = $item['price'] ?? 0;
                
                // Cek apakah produk sudah ada
                $existingProduct = Product::where('sku', $item['buyer_sku_code'])->first();
                
                // LOGIKA: Jika produk baru, pakai margin. 
                // Jika produk lama, jangan timpa harga jual (biar harga yang diedit admin gak ilang)
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
            return response()->json(['success' => false, 'message' => 'Failed to sync products'], 500);
        }
    }
}