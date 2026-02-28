<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class ProductController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * Ambil semua produk dengan sorting kategori dan nama.
     */
    public function index(Request $request)
    {
        try {
            $category = $request->query('category');
            $query = Product::query();

            if ($category) {
                $query->where('category', $category);
            }

            $products = $query->orderBy('category')->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $products,
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch products', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil data produk'], 500);
        }
    }

    /**
     * Update harga manual satu per satu.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'selling_price' => 'required|numeric|min:0'
        ]);

        try {
            $product = Product::findOrFail($id);
            
            $product->update([
                'selling_price' => $request->selling_price
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Harga berhasil diperbarui',
                'data' => $product
            ]);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 404);
        }
    }

    /**
     * Update margin massal (Optimasi DB Raw untuk ribuan produk).
     */
    public function bulkUpdateMargin(Request $request)
    {
        $request->validate([
            'margin' => 'required|numeric|min:0',
        ]);

        try {
            $margin = $request->margin;

            // Menggunakan DB::raw agar proses update selesai dalam milidetik
            $updatedCount = Product::query()->update([
                'selling_price' => DB::raw("cost_price + $margin")
            ]);

            return response()->json([
                'success' => true,
                'message' => "Margin Rp " . number_format($margin, 0, ',', '.') . " diterapkan ke {$updatedCount} produk",
                'data' => ['updated_count' => $updatedCount]
            ], 200);

        } catch (Exception $e) {
            Log::error('Bulk margin update failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui margin massal'], 500);
        }
    }

    /**
     * Sinkronisasi produk Digiflazz (Anti-Timeout & Proteksi Harga).
     */
    public function sync(Request $request)
    {
        // Menaikkan limit eksekusi dan memori untuk mencegah error 500
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        try {
            $category = $request->query('category');
            $response = $this->digiflazzService->getPriceList($category);

            if (!$response['success']) {
                return response()->json(['success' => false, 'message' => $response['message']], 400);
            }

            $defaultMargin = config('feepay.margin', 2000);
            $syncedCount = 0;

            // Database Transaction untuk performa dan keamanan data
            DB::transaction(function () use ($response, $defaultMargin, &$syncedCount) {
                foreach ($response['data'] as $item) {
                    $sku = $item['buyer_sku_code'];
                    $costPrice = $item['price'] ?? 0;
                    $status = ($item['buyer_product_status'] && $item['seller_product_status']) ? 'active' : 'inactive';
                    
                    $existingProduct = Product::where('sku', $sku)->first();

                    if ($existingProduct) {
                        $sellingPrice = $existingProduct->selling_price;
                        
                        // Proteksi Rugi: Jika modal naik melebihi harga jual lama
                        if ($costPrice >= $sellingPrice) {
                            $sellingPrice = $costPrice + $defaultMargin;
                        }
                    } else {
                        $sellingPrice = $costPrice + $defaultMargin;
                    }

                    Product::updateOrCreate(
                        ['sku' => $sku],
                        [
                            'name' => $item['product_name'] ?? 'Unknown',
                            'category' => $item['category'] ?? 'General',
                            'brand' => $item['brand'] ?? null,
                            'cost_price' => $costPrice,
                            'selling_price' => $sellingPrice,
                            'status' => $status,
                        ]
                    );
                    $syncedCount++;
                }
            });

            return response()->json([
                'success' => true,
                'message' => "Sinkronisasi {$syncedCount} produk berhasil",
                'data' => ['synced_count' => $syncedCount]
            ], 200);

        } catch (Exception $e) {
            Log::error('Product sync failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ]);
            return response()->json(['success' => false, 'message' => 'Gagal sinkronisasi: ' . $e->getMessage()], 500);
        }
    }
}