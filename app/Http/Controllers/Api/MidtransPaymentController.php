<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createPayment(Request $request): JsonResponse
    {
        try {
            // Validasi input
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

            // Ambil order dari database
            $order = Order::where('order_id', $request->order_id)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Cek apakah order sudah punya snap token yang masih valid
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

            // Cek status order
            if ($order->status !== OrderStatus::PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be paid. Status: ' . $order->status->value,
                ], 400);
            }

            // SECURITY: Ambil harga dari database berdasarkan SKU
            $product = Product::where('sku', $order->sku)->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            // Gunakan selling_price dari database, bukan dari user input
            $amount = (int) $product->selling_price;

            // Validasi amount
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid product price',
                ], 400);
            }

            // Generate Snap Token
            $snapToken = $this->midtransService->createSnapToken(
                $order->order_id,
                $amount,
                $order->customer_email,
                $order->product_name
            );

            // Update order dengan snap token
            $order->update([
                'midtrans_snap_token' => $snapToken,
            ]);

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
     * @param Request $request
     * @return JsonResponse
     */
    public function handleNotification(Request $request): JsonResponse
    {
        try {
            // Get notification data
            $notificationData = $request->all();

            Log::info('Midtrans notification received', [
                'data' => $notificationData,
            ]);

            // SECURITY: Verify signature key
            if (!$this->midtransService->verifySignature($notificationData)) {
                Log::warning('Invalid signature from Midtrans webhook', [
                    'data' => $notificationData,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 401);
            }

            // Get notification object
            $notification = $this->midtransService->getNotification();

            $orderId = $notification->order_id;
            $transactionStatus = $notification->transaction_status;
            $fraudStatus = $notification->fraud_status ?? 'accept';
            $transactionId = $notification->transaction_id;
            $paymentType = $notification->payment_type;
            $transactionTime = $notification->transaction_time;

            // Cari order berdasarkan order_id
            $order = Order::where('order_id', $orderId)->first();

            if (!$order) {
                Log::warning('Order not found for Midtrans notification', [
                    'order_id' => $orderId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // DATABASE TRANSACTION: Update status secara atomik
            DB::beginTransaction();

            try {
                // Update Midtrans transaction details
                $order->update([
                    'midtrans_transaction_id' => $transactionId,
                    'midtrans_payment_type' => $paymentType,
                    'midtrans_transaction_status' => $transactionStatus,
                    'midtrans_transaction_time' => $transactionTime,
                ]);

                // Handle berdasarkan status
                $message = '';
                $shouldProcessDigiflazz = false;
                $newStatus = null;
                $statusNote = null;

                switch ($transactionStatus) {
                    case 'capture':
                    case 'settlement':
                        // Payment sukses
                        if ($fraudStatus == 'accept') {
                            $newStatus = OrderStatus::PROCESSING;
                            $statusNote = "Payment {$transactionStatus} - Transaction ID: {$transactionId}";
                            $message = 'Payment successful';
                            $shouldProcessDigiflazz = true;
                        } else {
                            $newStatus = OrderStatus::PENDING;
                            $statusNote = 'Payment under fraud review';
                            $message = 'Payment under review';
                        }
                        break;

                    case 'pending':
                        // Menunggu pembayaran
                        $newStatus = OrderStatus::PENDING;
                        $statusNote = "Payment pending - {$paymentType}";
                        $message = 'Payment pending';
                        break;

                    case 'deny':
                        // Pembayaran ditolak
                        $newStatus = OrderStatus::FAILED;
                        $statusNote = 'Payment denied by gateway';
                        $message = 'Payment denied';
                        break;

                    case 'expire':
                        // Pembayaran kadaluarsa
                        $newStatus = OrderStatus::FAILED;
                        $statusNote = 'Payment expired';
                        $message = 'Payment expired';
                        break;

                    case 'cancel':
                        // Pembayaran dibatalkan
                        $newStatus = OrderStatus::FAILED;
                        $statusNote = 'Payment cancelled by user';
                        $message = 'Payment cancelled';
                        break;

                    default:
                        $statusNote = "Unknown payment status: {$transactionStatus}";
                        $message = 'Unknown payment status';
                        break;
                }

                // Update status dan log perubahan
                if ($newStatus) {
                    $order->status = $newStatus;
                    $order->save();
                    
                    // Log status change
                    $order->logStatusChange($newStatus, $statusNote);
                }

                DB::commit();

                Log::info('Order status updated', [
                    'order_id' => $orderId,
                    'transaction_status' => $transactionStatus,
                    'order_status' => $order->status->value,
                    'will_process_digiflazz' => $shouldProcessDigiflazz,
                ]);

                // ✅ INTEGRATION: Call endpoint confirm yang sudah ada
                if ($shouldProcessDigiflazz) {
                    $this->processToDigiflazz($order);
                }

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'order_id' => $orderId,
                        'transaction_status' => $transactionStatus,
                        'order_status' => $order->status->value,
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ PERBAIKAN: Panggil logic yang SUDAH ADA di OrderController
     * Gunakan logic dari endpoint: POST /admin/x7k2m/orders/{id}/confirm
     *
     * @param Order $order
     * @return void
     */
    private function processToDigiflazz(Order $order): void
    {
        try {
            // Pastikan order belum pernah diproses
            if ($order->confirmed_at !== null) {
                Log::info('Order already processed to Digiflazz', [
                    'order_id' => $order->order_id,
                ]);
                return;
            }

            Log::info('Processing order to Digiflazz', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
            ]);

            // ✅ GUNAKAN LOGIC YANG SUDAH ADA
            // Panggil OrderController->confirm() atau gunakan service yang sama
            // Sesuaikan dengan controller/service yang handle endpoint /admin/x7k2m/orders/{id}/confirm
            
            // Option 1: Jika ada OrderService
            // $orderService = app(\App\Services\OrderService::class);
            // $result = $orderService->confirmAndProcessToDigiflazz($order);
            
            // Option 2: Jika menggunakan DigiflazzService langsung
            $digiflazzService = app(\App\Services\DigiflazzService::class);
            $result = $digiflazzService->placeOrder(
                $order->sku,
                $order->target_number,
                $order->order_id
            );

            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Digiflazz transaction failed');
            }

            // Update order
            $order->update([
                'sn' => $result['data']['sn'] ?? null,
                'confirmed_at' => now(),
            ]);
            
            // Gunakan method yang sudah ada
            $order->markAsSuccess();

            Log::info('Order processed to Digiflazz successfully', [
                'order_id' => $order->order_id,
                'sn' => $result['data']['sn'] ?? null,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process order to Digiflazz', [
                'order_id' => $order->order_id,
                'error' => $e->getMessage(),
            ]);

            // Gunakan method yang sudah ada
            $order->markAsFailed(null, 'Digiflazz processing failed: ' . $e->getMessage());
        }
    }
}