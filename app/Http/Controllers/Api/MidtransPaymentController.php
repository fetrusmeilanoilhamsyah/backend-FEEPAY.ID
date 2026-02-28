<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Mail\OrderFailed;
use App\Jobs\SendOrderSuccessEmail;
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
                    'errors'  => $validator->errors(),
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
                    'data'    => [
                        'snap_token' => $order->midtrans_snap_token,
                        'order_id'   => $order->order_id,
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
                'amount'   => $amount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment token created successfully',
                'data'    => [
                    'snap_token'     => $snapToken,
                    'order_id'       => $order->order_id,
                    'amount'         => $amount,
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
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Midtrans Webhook/Notification
     * POST /api/callback/midtrans
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
     * Process order to Digiflazz setelah Midtrans konfirmasi payment berhasil.
     *
     * ✅ FIX B1: Gabungkan target_number + zone_id sebelum kirim ke Digiflazz
     * Aturan Digiflazz untuk game dengan Server ID (ML, Genshin, HOK):
     * customer_no = target_number + zone_id (digabung tanpa spasi atau pemisah)
     */
    private function processToDigiflazz(Order $order): void
    {
        try {
            // IDEMPOTENCY CHECK — cegah double process
            if ($order->confirmed_at !== null) {
                Log::info('Order already processed to Digiflazz (idempotency check)', [
                    'order_id'     => $order->order_id,
                    'confirmed_at' => $order->confirmed_at,
                ]);
                return;
            }

            // ✅ FIX B1: Gabungkan zone_id ke target_number sesuai aturan Digiflazz
            // Sama persis dengan logika di OrderController::confirm()
            $target = $order->target_number;
            if (!empty($order->zone_id)) {
                $target = $order->target_number . $order->zone_id;
            }

            Log::info('Processing order to Digiflazz', [
                'order_id'      => $order->order_id,
                'sku'           => $order->sku,
                'target_number' => $order->target_number,
                'zone_id'       => $order->zone_id,
                'final_target'  => $target,
            ]);

            $digiflazzService = app(\App\Services\DigiflazzService::class);

            $result = $digiflazzService->placeOrder(
                $order->sku,
                $target,   // ✅ sudah digabung dengan zone_id
                $order->order_id
            );

            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Digiflazz transaction failed');
            }

            $order->update(['confirmed_at' => now()]);

            $order->logStatusChange(
                OrderStatus::PROCESSING,
                'Order sent to Digiflazz - Waiting for callback confirmation'
            );

            Log::info('Order sent to Digiflazz successfully', [
                'order_id' => $order->order_id,
                'status'   => 'processing',
                'note'     => 'Waiting for Digiflazz callback',
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process order to Digiflazz', [
                'order_id' => $order->order_id,
                'error'    => $e->getMessage(),
            ]);

            $order->markAsFailed(null, 'Digiflazz processing error: ' . $e->getMessage());
            $this->sendFailedEmail($order, $e->getMessage());
        }
    }

    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            $userFriendlyReason = $this->translateFailedReason($rawReason);
            Mail::to($order->customer_email)->send(new OrderFailed($order, $userFriendlyReason));
            Log::info('Failed email sent', ['order_id' => $order->order_id]);
        } catch (Exception $mailException) {
            Log::error('Failed to send failed email', [
                'order_id' => $order->order_id,
                'error'    => $mailException->getMessage(),
            ]);
        }
    }

    private function translateFailedReason(?string $reason): string
    {
        if (!$reason) return 'Terjadi kesalahan saat memproses pesanan Anda.';

        $reason = strtolower($reason);

        if (str_contains($reason, 'saldo') || str_contains($reason, 'balance') || str_contains($reason, 'insufficient')) {
            return 'Layanan sedang tidak tersedia untuk sementara. Silakan coba lagi nanti atau hubungi Customer Service.';
        }
        if (str_contains($reason, 'nomor') || str_contains($reason, 'number') || str_contains($reason, 'destination')) {
            return 'Nomor tujuan tidak valid. Pastikan nomor yang Anda masukkan sudah benar.';
        }
        if (str_contains($reason, 'sku') || str_contains($reason, 'produk') || str_contains($reason, 'product')) {
            return 'Produk yang Anda pesan sedang tidak tersedia. Silakan pilih produk lain.';
        }
        if (str_contains($reason, 'timeout') || str_contains($reason, 'server') || str_contains($reason, 'connection')) {
            return 'Koneksi ke server provider terputus. Silakan coba lagi dalam beberapa menit.';
        }

        return 'Pesanan gagal diproses. Silakan coba lagi atau hubungi Customer Service kami.';
    }
}