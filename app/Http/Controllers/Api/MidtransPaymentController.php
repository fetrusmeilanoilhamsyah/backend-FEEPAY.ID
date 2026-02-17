<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Mail\OrderFailed;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Exception;

class MidtransPaymentController extends Controller
{
    protected $midtransService;

    public function __construct(MidtransService $midtransService)
    {
        $this->midtransService = $midtransService;
    }

    /**
     * Create Payment & Generate Snap Token
     * SECURITY: Harga diambil dari database berdasarkan SKU, bukan dari frontend
     *
     * POST /api/payments/midtrans/create
     */
    public function createPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|string|exists:orders,order_id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = Order::where('order_id', $request->order_id)->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            if ($order->hasMidtransToken() && $order->status === OrderStatus::PENDING) {
                return response()->json([
                    'success' => true,
                    'message' => 'Payment token already exists',
                    'data' => [
                        'snap_token' => $order->midtrans_snap_token,
                        'order_id' => $order->order_id,
                    ],
                ], 200);
            }

            if ($order->status !== OrderStatus::PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be paid. Status: ' . $order->status->value,
                ], 400);
            }

            $product = Product::where('sku', $order->sku)->first();

            if (!$product) {
                return response()->json(['success' => false, 'message' => 'Product not found'], 404);
            }

            $amount = (int) $product->selling_price;

            if ($amount <= 0) {
                return response()->json(['success' => false, 'message' => 'Invalid product price'], 400);
            }

            $snapToken = $this->midtransService->createSnapToken(
                $order->order_id,
                $amount,
                $order->customer_email,
                $order->product_name
            );

            $order->update(['midtrans_snap_token' => $snapToken]);

            Log::info('Payment created successfully', [
                'order_id' => $order->order_id,
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment token created successfully',
                'data' => [
                    'snap_token' => $snapToken,
                    'order_id' => $order->order_id,
                    'amount' => $amount,
                    'customer_email' => $order->customer_email,
                ],
            ], 201);

        } catch (Exception $e) {
            Log::error('Failed to create payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Midtrans Webhook/Notification
     * SECURITY: Verifikasi signature key untuk mencegah manipulasi status
     * DATABASE TRANSACTION: Update status secara atomik
     *
     * POST /api/callback/midtrans
     *
     * FLOW:
     * 1. User bayar via Midtrans
     * 2. Midtrans kirim webhook ke endpoint ini
     * 3. Verifikasi signature (keamanan)
     * 4. Parse transaction status
     * 5. Update order status di database
     * 6. Kalau payment sukses -> panggil processToDigiflazz()
     * 7. Return response ke Midtrans
     */
    public function handleNotification(Request $request): JsonResponse
    {
        try {
            $notificationData = $request->all();

            Log::info('Midtrans notification received', ['data' => $notificationData]);

            if (!$this->midtransService->verifySignature($notificationData)) {
                Log::warning('Invalid signature from Midtrans webhook', ['data' => $notificationData]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
            }

            $notification = $this->midtransService->getNotification();

            $orderId           = $notification->order_id;
            $transactionStatus = $notification->transaction_status;
            $fraudStatus       = $notification->fraud_status ?? 'accept';
            $transactionId     = $notification->transaction_id;
            $paymentType       = $notification->payment_type;
            $transactionTime   = $notification->transaction_time;

            $order = Order::where('order_id', $orderId)->first();

            if (!$order) {
                Log::warning('Order not found for Midtrans notification', ['order_id' => $orderId]);
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            DB::beginTransaction();

            try {
                $order->update([
                    'midtrans_transaction_id'     => $transactionId,
                    'midtrans_payment_type'        => $paymentType,
                    'midtrans_transaction_status'  => $transactionStatus,
                    'midtrans_transaction_time'    => $transactionTime,
                ]);

                $message                = '';
                $shouldProcessDigiflazz = false;
                $newStatus              = null;
                $statusNote             = null;

                switch ($transactionStatus) {
                    case 'capture':
                    case 'settlement':
                        if ($fraudStatus == 'accept') {
                            $newStatus              = OrderStatus::PROCESSING;
                            $statusNote             = "Payment {$transactionStatus} - Transaction ID: {$transactionId}";
                            $message                = 'Payment successful';
                            $shouldProcessDigiflazz = true;
                        } else {
                            $newStatus  = OrderStatus::PENDING;
                            $statusNote = 'Payment under fraud review';
                            $message    = 'Payment under review';
                        }
                        break;

                    case 'pending':
                        $newStatus  = OrderStatus::PENDING;
                        $statusNote = "Payment pending - {$paymentType}";
                        $message    = 'Payment pending';
                        break;

                    case 'deny':
                        $newStatus  = OrderStatus::FAILED;
                        $statusNote = 'Payment denied by gateway';
                        $message    = 'Payment denied';
                        break;

                    case 'expire':
                        $newStatus  = OrderStatus::FAILED;
                        $statusNote = 'Payment expired';
                        $message    = 'Payment expired';
                        break;

                    case 'cancel':
                        $newStatus  = OrderStatus::FAILED;
                        $statusNote = 'Payment cancelled by user';
                        $message    = 'Payment cancelled';
                        break;

                    default:
                        $statusNote = "Unknown payment status: {$transactionStatus}";
                        $message    = 'Unknown payment status';
                        break;
                }

                if ($newStatus) {
                    $order->status = $newStatus;
                    $order->save();
                    $order->logStatusChange($newStatus, $statusNote);
                }

                DB::commit();

                Log::info('Order status updated', [
                    'order_id'               => $orderId,
                    'transaction_status'     => $transactionStatus,
                    'order_status'           => $order->status->value,
                    'will_process_digiflazz' => $shouldProcessDigiflazz,
                ]);

                if ($shouldProcessDigiflazz) {
                    $this->processToDigiflazz($order);
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data'    => [
                        'order_id'           => $orderId,
                        'transaction_status' => $transactionStatus,
                        'order_status'       => $order->status->value,
                    ],
                ], 200);

            } catch (Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('Failed to process Midtrans notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process notification',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process order to Digiflazz
     *
     * PENJELASAN LENGKAP:
     * - Dipanggil setelah Midtrans konfirmasi payment berhasil
     * - Hit API Digiflazz untuk request pembelian produk
     * - Status tetap PROCESSING, tunggu callback Digiflazz
     * - Kalau Digiflazz langsung return Gagal (saldo habis, SKU salah, dll):
     *   1. Update status jadi FAILED
     *   2. Kirim email gagal ke customer (sendFailedEmail)
     *
     * @param Order $order
     * @return void
     */
    private function processToDigiflazz(Order $order): void
    {
        try {
            // IDEMPOTENCY CHECK
            // Webhook Midtrans bisa hit berkali-kali (retry), pastikan gak proses 2x
            if ($order->confirmed_at !== null) {
                Log::info('Order already processed to Digiflazz (idempotency check)', [
                    'order_id'     => $order->order_id,
                    'confirmed_at' => $order->confirmed_at,
                ]);
                return;
            }

            Log::info('Processing order to Digiflazz', [
                'order_id'      => $order->order_id,
                'order_db_id'   => $order->id,
                'sku'           => $order->sku,
                'target_number' => $order->target_number,
            ]);

            $digiflazzService = app(\App\Services\DigiflazzService::class);

            $result = $digiflazzService->placeOrder(
                $order->sku,
                $order->target_number,
                $order->order_id
            );

            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Digiflazz transaction failed');
            }

            // Status tetap PROCESSING, tunggu callback Digiflazz
            $order->update(['confirmed_at' => now()]);

            $order->logStatusChange(
                OrderStatus::PROCESSING,
                'Order sent to Digiflazz - Waiting for callback confirmation'
            );

            Log::info('Order sent to Digiflazz successfully', [
                'order_id'          => $order->order_id,
                'digiflazz_response' => $result['data'],
                'status'            => 'processing',
                'note'              => 'Waiting for Digiflazz callback',
            ]);

        } catch (Exception $e) {
            Log::error('Digiflazz placeOrder error', [
                'message' => $e->getMessage(),
                'sku'     => $order->sku,
                'ref_id'  => $order->order_id,
            ]);

            Log::error('Failed to process order to Digiflazz', [
                'order_id' => $order->order_id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            // Update status jadi FAILED
            $order->markAsFailed(null, 'Digiflazz processing error: ' . $e->getMessage());

            // ✅ FIX: Kirim email gagal ke customer
            // Sebelumnya email tidak dikirim saat Digiflazz langsung return error
            // (misal: saldo habis, SKU tidak valid, dll)
            $this->sendFailedEmail($order, $e->getMessage());
        }
    }

    /**
     * Send Failed Email to Customer
     *
     * PENJELASAN:
     * - Dipanggil saat Digiflazz langsung return error (synchronous failure)
     * - Contoh: saldo habis, SKU tidak valid, provider error
     * - Raw reason diterjemahkan ke bahasa yang user-friendly
     * - Error email TIDAK mengubah status order (try-catch terpisah)
     * - Error email dicatat di log untuk debugging
     *
     * @param Order $order
     * @param string|null $rawReason  Pesan error mentah dari Digiflazz
     * @return void
     */
    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            $userFriendlyReason = $this->translateFailedReason($rawReason);

            Mail::to($order->customer_email)
                ->send(new OrderFailed($order, $userFriendlyReason));

            Log::info('Failed email sent to customer', [
                'order_id'          => $order->order_id,
                'email'             => $order->customer_email,
                'raw_reason'        => $rawReason,
                'translated_reason' => $userFriendlyReason,
            ]);

        } catch (Exception $mailException) {
            // Error email TIDAK throw exception ke parent
            // Order tetap FAILED, cuma notifikasi email yang gagal
            Log::error('Failed to send failed email', [
                'order_id' => $order->order_id,
                'email'    => $order->customer_email,
                'error'    => $mailException->getMessage(),
                'note'     => 'Order status is still FAILED. Only email failed.',
            ]);
        }
    }

    /**
     * Translate Raw Digiflazz Error Reason to User-Friendly Message
     *
     * PENJELASAN:
     * - Pesan error dari Digiflazz kadang teknikal dan gak ramah untuk customer
     * - Method ini menerjemahkan ke bahasa Indonesia yang lebih mudah dipahami
     * - Kalau tidak ada keyword yang cocok, fallback ke pesan generic
     * - Pesan asli dari Digiflazz tetap disimpan di log untuk debugging
     *
     * @param string|null $reason
     * @return string
     */
    private function translateFailedReason(?string $reason): string
    {
        if (!$reason) {
            return 'Terjadi kesalahan saat memproses pesanan Anda.';
        }

        $reason = strtolower($reason);

        // Saldo Digiflazz habis
        if (str_contains($reason, 'saldo') || str_contains($reason, 'balance') || str_contains($reason, 'insufficient')) {
            return 'Layanan sedang tidak tersedia untuk sementara. Silakan coba lagi nanti atau hubungi Customer Service.';
        }

        // Nomor tujuan tidak valid
        if (str_contains($reason, 'nomor') || str_contains($reason, 'number') || str_contains($reason, 'destination')) {
            return 'Nomor tujuan tidak valid. Pastikan nomor yang Anda masukkan sudah benar.';
        }

        // SKU / produk tidak valid
        if (str_contains($reason, 'sku') || str_contains($reason, 'produk') || str_contains($reason, 'product')) {
            return 'Produk yang Anda pesan sedang tidak tersedia. Silakan pilih produk lain.';
        }

        // Timeout atau server error
        if (str_contains($reason, 'timeout') || str_contains($reason, 'server') || str_contains($reason, 'connection')) {
            return 'Koneksi ke server provider terputus. Silakan coba lagi dalam beberapa menit.';
        }

        return 'Pesanan gagal diproses. Silakan coba lagi atau hubungi Customer Service kami.';
    }
}