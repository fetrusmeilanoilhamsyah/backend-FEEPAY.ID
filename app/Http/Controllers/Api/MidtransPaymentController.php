<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Mail\OrderFailed;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use App\Services\MidtransService;
use App\Services\TelegramService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MidtransPaymentController extends Controller
{
    public function __construct(
        protected MidtransService $midtransService
    ) {}

    /**
     * ┌─────────────────────────────────────────────────────────────┐
     * │  CREATE PAYMENT                                             │
     * │  POST /api/payments/midtrans/create                        │
     * │                                                             │
     * │  Buat Snap Token untuk order yang sudah ada.               │
     * │  ⚠️  Harga DIAMBIL dari database — tidak dari request!     │
     * │  ✅  Kalau snap token sudah ada, reuse — jangan buat baru. │
     * └─────────────────────────────────────────────────────────────┘
     */
    public function createPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string|exists:orders,order_id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $order = Order::where('order_id', $request->order_id)->first();

            // Cegah order yang sudah diproses/dibatalkan membuat token baru
            if ($order->status !== OrderStatus::PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order sudah diproses atau dibatalkan.',
                ], 400);
            }

            // Reuse snap token yang sudah ada — cegah double token & error duplikat Midtrans
            if ($order->midtrans_snap_token) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'snap_token' => $order->midtrans_snap_token,
                        'order_id'   => $order->order_id,
                        'expires_in' => 3600,
                    ],
                ], 200);
            }

            // Ambil harga dari DB sebagai integer (Midtrans tidak terima desimal)
            $product = Product::where('sku', $order->sku)->first();
            if (!$product) {
                return response()->json(['success' => false, 'message' => 'Produk tidak ditemukan.'], 404);
            }

            $amount    = (int) $product->selling_price;
            $snapToken = $this->midtransService->createSnapToken(
                $order->order_id,
                $amount,
                $order->customer_email,
                $order->product_name
            );

            $order->update(['midtrans_snap_token' => $snapToken]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'snap_token' => $snapToken,
                    'order_id'   => $order->order_id,
                    'expires_in' => 3600,
                ],
            ], 201);

        } catch (Exception $e) {
            Log::error('MidtransPaymentController::createPayment gagal', [
                'order_id' => $request->order_id ?? null,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ┌─────────────────────────────────────────────────────────────┐
     * │  HANDLE WEBHOOK                                             │
     * │  POST /api/midtrans/webhook                                │
     * │                                                             │
     * │  Menerima notifikasi pembayaran dari Midtrans.             │
     * │                                                             │
     * │  ⚠️  BUG HISTORY:                                          │
     * │  - Jangan pakai getNotification() / new Notification()!    │
     * │    Laravel sudah consume php://input via $request->all()   │
     * │    sehingga Notification() dapat data kosong.              │
     * │  - Selalu pakai $notificationData (dari $request->all()).  │
     * │                                                             │
     * │  ⚠️  FIELD midtrans_* harus ada di $fillable di Order.php  │
     * │    Kalau di $guarded, Eloquent update() diam-diam gagal.   │
     * └─────────────────────────────────────────────────────────────┘
     */
    public function handleNotification(Request $request): JsonResponse
    {
        try {
            // ✅ Ambil data dari request — JANGAN pakai getNotification()
            $notificationData = $request->all();

            // Validasi signature untuk memastikan webhook benar-benar dari Midtrans
            if (!$this->midtransService->verifySignature($notificationData)) {
                Log::warning('Midtrans webhook: signature tidak valid', ['ip' => $request->ip()]);
                return response()->json(['success' => false, 'message' => 'Signature tidak valid.'], 401);
            }

            // Ekstrak semua field yang dibutuhkan dari payload webhook
            $orderId           = $notificationData['order_id']           ?? null;
            $transactionStatus = $notificationData['transaction_status'] ?? null;
            $fraudStatus       = $notificationData['fraud_status']       ?? 'accept';
            $paymentType       = $notificationData['payment_type']       ?? null;
            $transactionId     = $notificationData['transaction_id']     ?? null;
            $transactionTime   = $notificationData['transaction_time']   ?? null;

            $order = Order::where('order_id', $orderId)->first();

            if (!$order) {
                Log::warning('Midtrans webhook: order tidak ditemukan', ['order_id' => $orderId]);
                return response()->json(['success' => false, 'message' => 'Order tidak ditemukan.'], 404);
            }

            // Abaikan webhook duplikat dari Midtrans kalau status sudah final
            if ($order->status->isFinal()) {
                return response()->json(['success' => true, 'message' => 'Status sudah final.'], 200);
            }

            DB::beginTransaction();

            // ✅ Untuk bank transfer, payment_type dari Midtrans = "bank_transfer" (generic)
            // Kita perlu ambil nama bank spesifik dari va_numbers[0].bank
            // Contoh: "bank_transfer" + bank "bni" → "bni_va"
            // Ini yang ditampilkan ke user sebagai nama bank di frontend
            if ($paymentType === 'bank_transfer' && isset($notificationData['va_numbers'][0])) {
                $vaData = $notificationData['va_numbers'][0];

                if (isset($vaData['bank'])) {
                    $paymentType = $vaData['bank'] . '_va';  // bni → bni_va, bca → bca_va
                }

                // Simpan nomor VA sebagai transaction_id (bukan UUID dari Midtrans)
                // Nomor VA inilah yang ditampilkan ke user untuk melakukan transfer
                if (isset($vaData['va_number'])) {
                    $transactionId = $vaData['va_number'];
                }
            }

            $order->update([
                'midtrans_transaction_id'     => $transactionId,     // Nomor VA atau transaction UUID
                'midtrans_payment_type'       => $paymentType,       // bni_va, bca_va, gopay, dll
                'midtrans_transaction_status' => $transactionStatus, // pending, settlement, expire, dll
                'midtrans_transaction_time'   => $transactionTime,
            ]);

            $shouldProcess = false;

            // capture = kartu kredit berhasil, settlement = transfer/VA sudah masuk
            if (in_array($transactionStatus, ['capture', 'settlement'])) {
                if ($fraudStatus === 'accept') {
                    $order->status = OrderStatus::PROCESSING;
                    $order->logStatusChange(OrderStatus::PROCESSING, 'Pembayaran sukses via Midtrans.');
                    $shouldProcess = true;
                }
            // deny/expire/cancel = pembayaran gagal atau kadaluarsa
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $order->status = OrderStatus::FAILED;
                $order->logStatusChange(OrderStatus::FAILED, 'Pembayaran gagal/dibatalkan via Midtrans.');
            }
            // pending = menunggu transfer (normal untuk VA), tidak perlu action

            $order->save();
            DB::commit();

            // Kirim ke Digiflazz hanya kalau pembayaran benar-benar sukses
            if ($shouldProcess) {
                $this->processToDigiflazz($order);
            }

            return response()->json(['success' => true], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Midtrans webhook exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memproses notifikasi.'], 500);
        }
    }

    /**
     * ┌─────────────────────────────────────────────────────────────┐
     * │  PROCESS TO DIGIFLAZZ                                       │
     * │  Dipanggil setelah pembayaran sukses (settlement/capture)  │
     * │                                                             │
     * │  ✅ Pakai lockForUpdate() untuk cegah race condition.      │
     * │     Jika 2 webhook datang bersamaan, hanya 1 yang jalan.   │
     * │  ✅ confirmed_at diset SEBELUM call API Digiflazz.         │
     * │     Kalau gagal, confirmed_at di-reset dan status = FAILED. │
     * └─────────────────────────────────────────────────────────────┘
     */
    private function processToDigiflazz(Order $order): void
    {
        try {
            DB::transaction(function () use ($order) {
                // Lock row untuk cegah double-processing dari webhook duplikat
                $locked = Order::lockForUpdate()->find($order->id);

                // Kalau confirmed_at sudah terisi, berarti sudah pernah dikirim — skip
                if ($locked->confirmed_at !== null) {
                    Log::info('processToDigiflazz: order sudah pernah dikirim, skip.', [
                        'order_id' => $locked->order_id,
                    ]);
                    return;
                }

                // Tandai dulu sebelum call API eksternal — cegah race condition
                $locked->update(['confirmed_at' => now()]);

                // Untuk produk dengan zone (misal Mobile Legends), target = ID + zone
                $target = $locked->target_number . ($locked->zone_id ?? '');

                $digiflazzService = app(DigiflazzService::class);
                $result = $digiflazzService->purchaseProduct($locked->sku, $target, $locked->order_id);

                // purchaseProduct() return raw Digiflazz response: $result['data']['status']
                // Status: "Sukses" | "Pending" | "Gagal"
                // "Pending" = sedang diproses provider — normal, tunggu callback Digiflazz
                $digiStatus = $result['data']['status'] ?? null;
                if ($digiStatus === 'Gagal') {
                    throw new Exception($result['data']['message'] ?? 'Transaksi Digiflazz gagal.');
                }

                $locked->logStatusChange(OrderStatus::PROCESSING, 'Order dikirim ke Digiflazz setelah pembayaran Midtrans.');

                TelegramService::notify(
                    "📦 *ORDER DIKIRIM KE DIGIFLAZZ*\n" .
                    "----------------------------------\n" .
                    "*Order ID:* #{$locked->order_id}\n" .
                    "*Produk:* {$locked->product_name}\n" .
                    "*Target:* {$locked->target_number}\n" .
                    "*Nominal:* Rp " . number_format($locked->total_price, 0, ',', '.') . "\n" .
                    "----------------------------------\n" .
                    "_Menunggu konfirmasi provider..._"
                );
            });

        } catch (Exception $e) {
            Log::error('processToDigiflazz gagal: ' . $e->getMessage(), ['order_id' => $order->order_id]);

            // Rollback: reset confirmed_at dan tandai order FAILED
            DB::transaction(function () use ($order, $e) {
                $fresh = Order::lockForUpdate()->find($order->id);
                if ($fresh && !$fresh->status->isFinal()) {
                    $fresh->update([
                        'status'       => OrderStatus::FAILED->value,
                        'confirmed_at' => null,
                    ]);
                    $fresh->logStatusChange(OrderStatus::FAILED, 'Auto-Fail: ' . $e->getMessage());
                }
            });

            // Kirim email notifikasi gagal ke customer
            try {
                Mail::to($order->customer_email)->send(new OrderFailed($order, $e->getMessage()));
            } catch (Exception $mailEx) {
                Log::error('Email gagal setelah processToDigiflazz error', ['error' => $mailEx->getMessage()]);
            }
        }
    }
}