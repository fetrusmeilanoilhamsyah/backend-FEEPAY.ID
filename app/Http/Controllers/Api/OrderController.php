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

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
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
                'total_price'    => $product->selling_price,
                'status'         => OrderStatus::PENDING->value,
            ]);

            $order->logStatusChange(OrderStatus::PENDING, 'Order dibuat oleh pelanggan.');

            DB::commit();

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

    public function confirm(ConfirmOrderRequest $request, int $id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $order = Order::lockForUpdate()->find($id);

            if (!$order) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
            }

            if ($order->status !== OrderStatus::PENDING) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Pesanan sudah diproses sebelumnya.'], 400);
            }

            $target = $order->target_number;
            if ($order->zone_id) {
                $target = $order->target_number . $order->zone_id;
            }

            $digiflazz = $this->digiflazzService->placeOrder($order->sku, $target, $order->order_id);

            if (!$digiflazz['success']) {
                $order->markAsFailed($request->user()->id, 'Digiflazz: ' . $digiflazz['message']);
                DB::commit();

                TelegramService::notify(
                    "⚠️ *TRANSAKSI GAGAL*\n" .
                    "----------------------------------\n" .
                    "*Order ID:* #{$order->order_id}\n" .
                    "*Produk:* {$order->product_name}\n" .
                    "*Target:* {$target}\n" .
                    "*Pesan:* {$digiflazz['message']}\n" .
                    "----------------------------------\n" .
                    "_Cek saldo Digiflazz!_"
                );

                return response()->json([
                    'success' => false,
                    'message' => $digiflazz['message'],
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

    public function sync(Request $request, string $orderId): JsonResponse
    {
        try {
            // ✅ PERBAIKAN: Tambah lockForUpdate untuk prevent concurrent sync
            $order = DB::transaction(function () use ($orderId) {
                return Order::where('order_id', $orderId)->lockForUpdate()->first();
            });

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
            }

            if ($order->status->isFinal()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Status sudah final, tidak perlu sinkronisasi.',
                    'status'  => $order->status->value,
                ], 200);
            }

            $result = $this->digiflazzService->checkOrderStatus($order->order_id);

            if (!$result['success']) {
                return response()->json(['success' => false, 'message' => $result['message']], 400);
            }

            $apiData    = $result['data'];
            $digiStatus = strtolower($apiData['status'] ?? '');

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
                        // ✅ PERBAIKAN: Dispatch ke queue instead of sync send
                        \App\Jobs\SendOrderSuccessEmail::dispatch(
                            $order, 
                            Product::where('sku', $order->sku)->first()
                        );
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

    public function index(Request $request): JsonResponse
    {
        // ✅ PERBAIKAN: Tambah filtering dan optimize eager loading
        $query = Order::query();
        
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        
        if ($startDate = $request->query('start_date')) {
            $query->where('created_at', '>=', $startDate);
        }
        
        if ($endDate = $request->query('end_date')) {
            $query->where('created_at', '<=', $endDate);
        }
        
        $orders = $query->with([
            'statusHistories' => function ($q) {
                $q->latest()->limit(5);
            }
        ])
        ->orderBy('created_at', 'desc')
        ->paginate($request->query('per_page', 50));

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $order = Order::where('order_id', $orderId)->first();

        if (!$order || strtolower($request->email) !== strtolower($order->customer_email)) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }
}