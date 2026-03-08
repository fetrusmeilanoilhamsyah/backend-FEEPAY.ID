<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendOrderFailedEmail;
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function handleNotification(Request $request): JsonResponse
    {
        try {
            // ✅ PERBAIKAN: Validasi Content-Type
            if (!$request->isJson()) {
                Log::warning('Midtrans webhook: bukan JSON', [
                    'content_type' => $request->header('Content-Type'),
                    'ip' => $request->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Content-Type must be application/json'
                ], 400);
            }

            $notificationData = $request->all();

            if (!$this->midtransService->verifySignature($notificationData)) {
                Log::warning('Midtrans webhook: signature tidak valid', ['ip' => $request->ip()]);
                return response()->json(['success' => false, 'message' => 'Signature tidak valid.'], 401);
            }

            $notification = $this->midtransService->getNotification();
            
            // ✅ PERBAIKAN: Wrap dalam transaction + lockForUpdate
            DB::transaction(function () use ($notification) {
                $order = Order::where('order_id', $notification->order_id)
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    Log::warning('Midtrans webhook: order tidak ditemukan', [
                        'order_id' => $notification->order_id
                    ]);
                    return;
                }

                if ($order->status->isFinal()) {
                    Log::info('Midtrans webhook: status sudah final, skip', [
                        'order_id' => $order->order_id,
                        'status' => $order->status->value
                    ]);
                    return;
                }

                $order->update([
                    'midtrans_transaction_id'     => $notification->transaction_id,
                    'midtrans_payment_type'       => $notification->payment_type,
                    'midtrans_transaction_status' => $notification->transaction_status,
                    'midtrans_transaction_time'   => $notification->transaction_time,
                ]);

                $transactionStatus = $notification->transaction_status;
                $fraudStatus       = $notification->fraud_status ?? 'accept';

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

                if ($shouldProcess) {
                    $this->processToDigiflazz($order);
                }
            });

            return response()->json(['success' => true], 200);

        } catch (Exception $e) {
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
                $result           = $digiflazzService->placeOrder($locked->sku, $target, $locked->order_id);

                if (!$result['success']) {
                    throw new Exception($result['message'] ?? 'Transaksi Digiflazz gagal.');
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

            // ✅ PERBAIKAN: Dispatch ke queue
            SendOrderFailedEmail::dispatch($order, $e->getMessage());
        }
    }
}