<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Mail\OrderSuccess;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use App\Services\TelegramService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    // ─── Public: Buat Order Baru ──────────────────────────────────────────────

    /**
     * POST /api/orders/create
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            // Idempotency: cegah order duplikat dari klik ganda
            $idempotencyKey = $request->header('X-Idempotency-Key');
            if ($idempotencyKey) {
                $cacheKey = 'idempotency:order:' . hash('sha256', $idempotencyKey);
                $cached   = Cache::get($cacheKey);
                if ($cached) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Order sudah dibuat sebelumnya.',
                        'data'    => $cached,
                    ], 200);
                }
            }

            DB::beginTransaction();

            // Ambil produk — harga dari database, bukan dari request
            $product = Product::where('sku', $request->sku)->where('status', 'active')->first();
            if (!$product) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan atau tidak aktif.'], 404);
            }

            $orderId = 'FP' . strtoupper(Str::random(12));

            $order = Order::create([
                'order_id'       => $orderId,
                'sku'            => $request->sku,
                'product_name'   => $product->name,
                'target_number'  => $request->target_number,
                'zone_id'        => $request->zone_id,
                'customer_email' => strtolower($request->customer_email),
                'total_price'    => $product->selling_price, // Harga dari DB, bukan dari user
                'status'         => OrderStatus::PENDING->value,
            ]);

            $order->logStatusChange(OrderStatus::PENDING, 'Order dibuat oleh pelanggan.');

            DB::commit();

            // Simpan ke idempotency cache — hanya data minimal, bukan data sensitif
            if ($idempotencyKey) {
                $cachePayload = [
                    'order_id'     => $order->order_id,
                    'product_name' => $order->product_name,
                    'total_price'  => $order->total_price,
                    'status'       => $order->status,
                    'created_at'   => $order->created_at,
                ];
                Cache::put($cacheKey, $cachePayload, now()->addHours(24));
            }

            Log::info('Order dibuat', ['order_id' => $order->order_id]);

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat.',
                'data'    => $order,
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('OrderController::store gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal membuat pesanan.'], 500);
        }
    }

    // ─── Admin: Konfirmasi & Kirim ke Digiflazz ───────────────────────────────

    /**
     * POST /api/admin/{path}/orders/{id}/confirm
     */
    public function confirm(ConfirmOrderRequest $request, int $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            // lockForUpdate mencegah dua request admin mengonfirmasi order yang sama serentak
            $order = Order::lockForUpdate()->find($id);

            if (!$order) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
            }

            if ($order->status !== OrderStatus::PENDING) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pesanan sudah diproses sebelumnya.'], 400);
            }

            // Gabungkan target + zone_id jika ada
            $target = $order->target_number;
            if ($order->zone_id) {
                $target = $order->target_number . $order->zone_id;
            }

            // purchaseProduct() return raw Digiflazz response
            // Status: "Sukses" | "Pending" | "Gagal"
            $digiflazz  = $this->digiflazzService->purchaseProduct($order->sku, $target, $order->order_id);
            $digiStatus = $digiflazz['data']['status'] ?? null;
            $digiMsg    = $digiflazz['data']['message'] ?? 'Transaksi Digiflazz gagal.';

            if ($digiStatus === 'Gagal') {
                $order->markAsFailed($request->user()->id, 'Digiflazz: ' . $digiMsg);
                DB::commit();

                TelegramService::notify(
                    "⚠️ *TRANSAKSI GAGAL*\n" .
                    "----------------------------------\n" .
                    "*Order ID:* #{$order->order_id}\n" .
                    "*Produk:* {$order->product_name}\n" .
                    "*Target:* {$target}\n" .
                    "*Pesan:* {$digiMsg}\n" .
                    "----------------------------------\n" .
                    "_Cek saldo Digiflazz!_"
                );

                return response()->json([
                    'success' => false,
                    'message' => $digiMsg,
                ], 400);
            }

            $apiData = $digiflazz['data'];

            $order->update([
                'status'       => OrderStatus::PROCESSING->value,
                'sn'           => $apiData['sn'] ?? '-',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            $order->logStatusChange(OrderStatus::PROCESSING, 'Diteruskan ke provider Digiflazz.', $request->user()->id);

            DB::commit();

            TelegramService::notify(
                "⏳ *TRANSAKSI DIPROSES*\n" .
                "----------------------------------\n" .
                "*Order ID:* #{$order->order_id}\n" .
                "*Produk:* {$order->product_name}\n" .
                "*Target:* {$target}\n" .
                "*Nominal:* Rp " . number_format($order->total_price, 0, ',', '.') . "\n" .
                "----------------------------------\n" .
                "_Menunggu callback sukses..._"
            );

            return response()->json([
                'success' => true,
                'message' => 'Pesanan sedang diproses oleh provider.',
                'sn'      => $order->sn,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('OrderController::confirm gagal', ['id' => $id, 'error' => $e->getMessage()]);
            TelegramService::notify("🚨 *SYSTEM ERROR:* Gagal konfirmasi order #{$id}.");
            return response()->json(['success' => false, 'message' => 'Gagal memproses konfirmasi.'], 500);
        }
    }

    // ─── Admin: Sinkronisasi Status dari Digiflazz ────────────────────────────

    /**
     * POST /api/admin/{path}/orders/{orderId}/sync
     * Fix BUG-13: method sebelumnya bernama syncStatus dengan signature berbeda.
     * Sekarang menggunakan orderId sebagai string dari route parameter.
     */
    public function sync(Request $request, string $orderId): JsonResponse
    {
        try {
            $order = Order::where('order_id', $orderId)->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
            }

            // Tidak perlu sync jika sudah final
            if ($order->status->isFinal()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Status sudah final, tidak perlu sinkronisasi.',
                    'status'  => $order->status->value,
                ], 200);
            }

            // checkStatus() return raw Digiflazz response
            $result     = $this->digiflazzService->checkStatus($order->order_id);
            $apiData    = $result['data'] ?? [];
            $digiStatus = strtolower($apiData['status'] ?? '');

            if (empty($digiStatus)) {
                return response()->json(['success' => false, 'message' => 'Gagal mengambil status dari Digiflazz.'], 400);
            }

            $newStatus = match($digiStatus) {
                'sukses' => OrderStatus::SUCCESS,
                'gagal'  => OrderStatus::FAILED,
                default  => OrderStatus::PROCESSING,
            };

            if ($order->status !== $newStatus) {
                DB::beginTransaction();

                $order->update([
                    'status' => $newStatus->value,
                    'sn'     => $apiData['sn'] ?? $order->sn,
                ]);

                $order->logStatusChange($newStatus, 'Update manual dari admin via sinkronisasi.', $request->user()->id);

                DB::commit();

                $emoji = ($newStatus === OrderStatus::SUCCESS) ? '✅' : '❌';
                TelegramService::notify(
                    "{$emoji} *UPDATE STATUS (SYNC MANUAL)*\n" .
                    "----------------------------------\n" .
                    "*Order ID:* #{$order->order_id}\n" .
                    "*Produk:* {$order->product_name}\n" .
                    "*Status Baru:* " . strtoupper($digiStatus) . "\n" .
                    "*SN:* `{$order->sn}`\n" .
                    "----------------------------------"
                );

                if ($newStatus === OrderStatus::SUCCESS) {
                    try {
                        $product = \App\Models\Product::where('sku', $order->sku)->first()
                            ?? new \App\Models\Product([
                                'name'          => $order->product_name,
                                'sku'           => $order->sku,
                                'selling_price' => $order->total_price,
                            ]);
                        Mail::to($order->customer_email)->send(new OrderSuccess($order, $product));
                    } catch (Exception $mailEx) {
                        Log::error('Email sukses gagal dikirim setelah sync', [
                            'order_id' => $order->order_id,
                            'error'    => $mailEx->getMessage(),
                        ]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Status berhasil disinkronkan.',
                'status'  => $order->status->value,
            ]);

        } catch (Exception $e) {
            Log::error('OrderController::sync gagal', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal sinkronisasi status.'], 500);
        }
    }

    // ─── Admin: Daftar Semua Order ────────────────────────────────────────────

    /**
     * GET /api/admin/{path}/orders
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Order::with('statusHistories')
                ->orderBy('created_at', 'desc')
                ->paginate(50),
        ]);
    }

    // ─── Public: Cek Status Order oleh Pelanggan ─────────────────────────────

    /**
     * POST /api/orders/{orderId}
     * Email diperlukan untuk membuktikan kepemilikan order.
     */
    public function show(Request $request, string $orderId): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $order = Order::where('order_id', $orderId)->first();

        // Bandingkan email secara case-insensitive
        if (!$order || strtolower($request->email) !== strtolower($order->customer_email)) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }
}