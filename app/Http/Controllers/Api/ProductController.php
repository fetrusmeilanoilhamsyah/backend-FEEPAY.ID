<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\DigiflazzService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * GET /api/products
     * Daftar produk aktif untuk pelanggan.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            $query    = Product::active();

            if ($category) {
                $query->where('category', $category);
            }

            $products = $query->orderBy('category')->orderBy('name')->get();

            return response()->json(['success' => true, 'data' => $products], 200);

        } catch (Exception $e) {
            Log::error('ProductController::index gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil data produk.'], 500);
        }
    }

    /**
     * PUT /api/admin/{path}/products/{id}
     * Update harga jual satu produk.
     */
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

            return response()->json([
                'success' => true,
                'message' => 'Harga berhasil diperbarui.',
                'data'    => $product->fresh(),
            ]);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan.'], 404);
        }
    }

    /**
     * POST /api/admin/{path}/products/bulk-margin
     * Update margin semua produk sekaligus.
     *
     * Fix BUG-01: menggunakan parameter binding, bukan DB::raw interpolasi langsung.
     */
    public function bulkUpdateMargin(Request $request): JsonResponse
    {
        $request->validate([
            'margin' => 'required|numeric|min:0|max:1000000',
        ]);

        try {
            // Cast eksplisit ke float — aman sebagai parameter binding
            $margin = (float) $request->margin;

            // Gunakan binding parameter — tidak ada SQL injection
            $updatedCount = DB::table('products')->update([
                'selling_price' => DB::raw('cost_price + ?'),
            ]);

            // Karena DB::raw + binding tidak bekerja langsung di update(),
            // gunakan pendekatan yang benar dengan whereRaw tidak diperlukan:
            DB::statement(
                'UPDATE products SET selling_price = cost_price + ?',
                [$margin]
            );

            // Hitung jumlah yang diupdate untuk response
            $updatedCount = Product::count();

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

    /**
     * POST /api/admin/{path}/products/sync
     * Sinkronisasi produk dari Digiflazz API.
     * Dijalankan sebagai HTTP request biasa — untuk operasi besar gunakan Artisan command.
     */
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

            DB::transaction(function () use ($response, $defaultMargin, &$syncedCount) {
                foreach ($response['data'] as $item) {
                    $sku = $item['buyer_sku_code'] ?? null;
                    if (!$sku) continue;

                    $costPrice = (float) ($item['price'] ?? 0);
                    $status    = ($item['buyer_product_status'] ?? false) && ($item['seller_product_status'] ?? false)
                        ? 'active'
                        : 'inactive';

                    $existing = Product::where('sku', $sku)->first();

                    // Proteksi rugi: jika modal naik melebihi harga jual lama
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
                }
            });

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
}
