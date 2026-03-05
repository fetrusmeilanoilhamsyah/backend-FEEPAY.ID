<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\ConfirmOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use App\Services\TelegramService;
use App\Mail\OrderSuccess;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Exception;

class OrderController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * Membuat Pesanan Baru
     */
    public function store(StoreOrderRequest $request)
    {
        try {
            $idempotencyKey = $request->header('X-Idempotency-Key');
            if ($idempotencyKey) {
                $cacheKey = 'idempotency:order:' . $idempotencyKey;
                $cached   = Cache::get($cacheKey);
                if ($cached) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Order created (cached)',
                        'data'    => $cached,
                    ], 201);
                }
            }

            DB::beginTransaction();

            $product = Product::where('sku', $request->sku)->first();

            if (!$product) {
                return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan'], 400);
            }

            // ✅ FIXED: Hapus date('his') yang predictable → full random 12 karakter
            $orderId = 'FP' . strtoupper(Str::random(12));

            $order = Order::create([
                'order_id'       => $orderId,
                'sku'            => $request->sku,
                'product_name'   => $product->name,
                'target_number'  => $request->target_number,
                'zone_id'        => $request->zone_id,
                'customer_email' => $request->customer_email,
                'total_price'    => $product->selling_price,
                'status'         => OrderStatus::PENDING->value,
            ]);

            DB::commit();

            if ($idempotencyKey) {
                Cache::put($cacheKey, $order, now()->addHours(24));
            }

            Log::info('Order Created successfully', ['order_id' => $order->order_id]);

            return response()->json(['success' => true, 'message' => 'Pesanan berhasil dibuat', 'data' => $order], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Order Store Failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal membuat pesanan'], 500);
        }
    }

    /**
     * Konfirmasi Pesanan & Eksekusi ke Digiflazz
     */
    public function confirm(ConfirmOrderRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $order = Order::lockForUpdate()->find($id);

            if (!$order) return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);

            if ($order->status !== OrderStatus::PENDING) {
                return response()->json(['success' => false, 'message' => 'Pesanan sudah diproses sebelumnya'], 400);
            }

            $target = $order->target_number;
            if ($order->zone_id) {
                $target = $order->target_number . $order->zone_id;
            }

            $digiflazz = $this->digiflazzService->placeOrder($order->sku, $target, $order->order_id);

            if (!$digiflazz['success']) {
                $order->markAsFailed($request->user()->id, 'Digiflazz: ' . $digiflazz['message']);
                DB::commit();

                TelegramService::notify("
⚠️ *DANGER: TRANSAKSI GAGAL (SISTEM)*
----------------------------------
*Order ID:* #{$order->order_id}
*Produk:* {$order->product_name}
*Target:* $target
*Eror:* Digiflazz Rejected
*Pesan:* {$digiflazz['message']}
----------------------------------
_Laporan: Segera cek saldo Digiflazz!_
                ");

                return response()->json(['success' => false, 'message' => $digiflazz['message']], 400);
            }

            $apiData = $digiflazz['data'];

            $order->update([
                'status'       => OrderStatus::PROCESSING,
                'sn'           => $apiData['sn'] ?? '-',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            $order->logStatusChange(OrderStatus::PROCESSING, 'Berhasil diteruskan ke provider', $request->user()->id);

            DB::commit();

            TelegramService::notify("
⏳ *TRANSAKSI DIPROSES*
----------------------------------
*Order ID:* #{$order->order_id}
*Produk:* {$order->product_name}
*Target:* $target
*Nominal:* Rp " . number_format($order->total_price, 0, ',', '.') . "
----------------------------------
_Menunggu callback sukses..._
            ");

            return response()->json(['success' => true, 'message' => 'Pesanan sedang diproses provider', 'sn' => $order->sn], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Confirm Order Failed', ['error' => $e->getMessage()]);
            TelegramService::notify("🚨 *SYSTEM ERROR:* Gagal konfirmasi order #{$id}.");
            return response()->json(['success' => false, 'message' => 'Sistem gagal memproses konfirmasi'], 500);
        }
    }

    /**
     * Sinkronisasi Status Order
     */
    public function syncStatus($orderId)
    {
        try {
            $order  = Order::where('order_id', $orderId)->firstOrFail();
            $result = $this->digiflazzService->checkOrderStatus($order->order_id);

            if ($result['success']) {
                $apiData    = $result['data'];
                $digiStatus = strtolower($apiData['status'] ?? '');

                $newStatus = match($digiStatus) {
                    'sukses' => OrderStatus::SUCCESS,
                    'gagal'  => OrderStatus::FAILED,
                    default  => OrderStatus::PROCESSING,
                };

                if ($order->status !== $newStatus) {
                    $order->update([
                        'status' => $newStatus,
                        'sn'     => $apiData['sn'] ?? $order->sn,
                    ]);

                    $order->logStatusChange($newStatus, "Update otomatis dari provider");

                    $emoji = ($newStatus === OrderStatus::SUCCESS) ? '✅' : '❌';
                    TelegramService::notify("
$emoji *UPDATE STATUS (SYNC)*
----------------------------------
*Order ID:* #{$order->order_id}
*Produk:* {$order->product_name}
*Status Baru:* " . strtoupper($digiStatus) . "
*SN:* `{$order->sn}`
----------------------------------
                    ");

                    if ($newStatus === OrderStatus::SUCCESS) {
                        try {
                            Mail::to($order->customer_email)->send(new OrderSuccess($order));
                        } catch (Exception $mailEx) {
                            Log::error('Email Notification Failed', ['error' => $mailEx->getMessage()]);
                        }
                    }
                }

                return response()->json(['success' => true, 'message' => 'Status diperbarui', 'status' => $order->status]);
            }

            return response()->json(['success' => false, 'message' => $result['message']], 400);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal sinkronisasi status'], 500);
        }
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => Order::orderBy('created_at', 'desc')->paginate(50)]);
    }

    public function show(Request $request, string $orderId)
    {
        $request->validate(['email' => 'required|email']);
        $order = Order::where('order_id', $orderId)->first();

        if (!$order || strtolower($request->email) !== strtolower($order->customer_email)) {
            return response()->json(['success' => false, 'message' => 'Pesanan tidak ditemukan'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }
}