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
     * Fix PROD-01: sebelumnya ada dua UPDATE sekaligus —
     * DB::table()->update() dengan DB::raw('cost_price + ?') yang INVALID
     * diikuti DB::statement() yang benar. Sekarang hanya satu query.
     */
    public function bulkUpdateMargin(Request $request): JsonResponse
    {
        $request->validate([
            'margin' => 'required|numeric|min:0|max:1000000',
        ]);

        try {
            $margin = (float) $request->margin;

            // Satu statement dengan parameter binding — aman dari SQL injection
            DB::statement('UPDATE products SET selling_price = cost_price + ?', [$margin]);

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
    /**
     * POST /api/products/verify-game-id
     * Verifikasi ID Game (ML, FF, dll)
     */
    public function verifyGameId(Request $request): JsonResponse
    {
        $request->validate([
            'game'    => 'required|string',
            'user_id' => 'required|string',
            'zone_id' => 'nullable|string',
        ]);

        try {
            $game   = strtoupper($request->game);
            $userId = $request->user_id;
            $zoneId = $request->zone_id;

            // Simulasi verifikasi atau gunakan API pihak ketiga jika ada
            // Untuk demo/MVP, kita kembalikan sukses jika ID > 5 digit
            if (strlen($userId) < 5) {
                return response()->json([
                    'success' => false,
                    'message' => 'ID tidak ditemukan atau terlalu pendek.'
                ], 404);
            }

            // Mock name generation based on ID
            $mockNames = ['SkyWatcher', 'ProPlayer', 'FeePayUser', 'Legendary', 'ShadowHunter'];
            $nameIdx   = (int) substr($userId, -1) % count($mockNames);
            $username  = $mockNames[$nameIdx];

            return response()->json([
                'success' => true,
                'data' => [
                    'username' => $username,
                    'game'     => $game,
                    'id'       => $userId . ($zoneId ? " ($zoneId)" : '')
                ]
            ]);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal verifikasi ID.'], 500);
        }
    }
}
