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

            if ($order->status !== OrderStatus::PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order sudah diproses atau dibatalkan.',
                ], 400);
            }

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
            return response()->json(['success' => false, 'message' => 'Gagal membuat token pembayaran.'], 500);
        }
    }

    public function handleNotification(Request $request): JsonResponse
    {
        try {
            $notificationData = $request->all();

            if (!$this->midtransService->verifySignature($notificationData)) {
                Log::warning('Midtrans webhook: signature tidak valid', ['ip' => $request->ip()]);
                return response()->json(['success' => false, 'message' => 'Signature tidak valid.'], 401);
            }

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

            if ($order->status->isFinal()) {
                return response()->json(['success' => true, 'message' => 'Status sudah final.'], 200);
            }

            DB::beginTransaction();

            // ✅ FIX #1: Handle bank_transfer (BNI, BCA, BRI, dll)
            if ($paymentType === 'bank_transfer' && isset($notificationData['va_numbers'][0])) {
                $vaData = $notificationData['va_numbers'][0];
                if (isset($vaData['bank'])) {
                    $paymentType = $vaData['bank'] . '_va';
                }
                if (isset($vaData['va_number'])) {
                    $transactionId = $vaData['va_number'];
                }
            }

            // ✅ FIX #2: Handle Mandiri echannel (Mandiri Bill Payment)
            // Mandiri pakai biller_code + bill_key, BUKAN va_numbers
            // Format simpan: "BILLER_CODE|BILL_KEY" untuk ditampilkan di frontend
            if ($paymentType === 'echannel') {
                $billerCode = $notificationData['biller_code'] ?? null;
                $billKey    = $notificationData['bill_key']    ?? null;
                if ($billerCode && $billKey) {
                    $transactionId = $billerCode . '|' . $billKey;
                }
            }

            $order->update([
                'midtrans_transaction_id'     => $transactionId,
                'midtrans_payment_type'       => $paymentType,
                'midtrans_transaction_status' => $transactionStatus,
                'midtrans_transaction_time'   => $transactionTime,
            ]);

            $shouldProcess = false;

            if (in_array($transactionStatus, ['capture', 'settlement'])) {
                if ($fraudStatus === 'accept') {
                    $order->status = OrderStatus::PROCESSING;
                    $order->logStatusChange(OrderStatus::PROCESSING, 'Pembayaran sukses via Midtrans.');
                    $shouldProcess = true;
                }
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $order->status = OrderStatus::FAILED;
                $order->logStatusChange(OrderStatus::FAILED, 'Pembayaran gagal/dibatalkan via Midtrans.');
            }

            $order->save();
            DB::commit();

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

    private function processToDigiflazz(Order $order): void
    {
        try {
            DB::transaction(function () use ($order) {
                $locked = Order::lockForUpdate()->find($order->id);

                if ($locked->confirmed_at !== null) {
                    Log::info('processToDigiflazz: order sudah pernah dikirim, skip.', [
                        'order_id' => $locked->order_id,
                    ]);
                    return;
                }

                $locked->update(['confirmed_at' => now()]);

                $target = $locked->target_number . ($locked->zone_id ?? '');

                $digiflazzService = app(DigiflazzService::class);
                $result = $digiflazzService->purchaseProduct($locked->sku, $target, $locked->order_id);

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

            try {
                Mail::to($order->customer_email)->send(new OrderFailed($order, $e->getMessage()));
            } catch (Exception $mailEx) {
                Log::error('Email gagal setelah processToDigiflazz error', ['error' => $mailEx->getMessage()]);
            }
        }
    }
}