<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MidtransService;
use App\Models\Order;
use App\Models\Product;
use App\Enums\OrderStatus;
use App\Mail\OrderFailed;
use App\Services\TelegramService;
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

    public function createPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_id' => 'required|string|exists:orders,order_id',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
            }

            $order = Order::where('order_id', $request->order_id)->first();

            // ✅ FIXED: Reuse snap token lama kalau sudah ada
            // Mencegah error "order_id has already been taken" dari Midtrans
            if ($order->midtrans_snap_token) {
                return response()->json(['success' => true, 'data' => ['snap_token' => $order->midtrans_snap_token, 'order_id' => $order->order_id]], 200);
            }

            $product = Product::where('sku', $order->sku)->first();
            $amount = (int) $product->selling_price;

            $snapToken = $this->midtransService->createSnapToken($order->order_id, $amount, $order->customer_email, $order->product_name);
            $order->update(['midtrans_snap_token' => $snapToken]);

            return response()->json(['success' => true, 'data' => ['snap_token' => $snapToken, 'order_id' => $order->order_id]], 201);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function handleNotification(Request $request): JsonResponse
    {
        try {
            $notificationData = $request->all();
            if (!$this->midtransService->verifySignature($notificationData)) {
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
            }

            $notification = $this->midtransService->getNotification();
            $order = Order::where('order_id', $notification->order_id)->first();

            if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);

            DB::beginTransaction();
            $order->update([
                'midtrans_transaction_id' => $notification->transaction_id,
                'midtrans_payment_type' => $notification->payment_type,
                'midtrans_transaction_status' => $notification->transaction_status,
                'midtrans_transaction_time' => $notification->transaction_time,
            ]);

            $shouldProcess = false;
            if (in_array($notification->transaction_status, ['capture', 'settlement'])) {
                if (($notification->fraud_status ?? 'accept') == 'accept') {
                    $order->status = OrderStatus::PROCESSING;
                    $order->logStatusChange(OrderStatus::PROCESSING, "Payment success via Midtrans");
                    $shouldProcess = true;
                }
            } elseif (in_array($notification->transaction_status, ['deny', 'expire', 'cancel'])) {
                $order->status = OrderStatus::FAILED;
                $order->logStatusChange(OrderStatus::FAILED, "Payment failed/cancelled via Midtrans");
            }

            $order->save();
            DB::commit();

            if ($shouldProcess) {
                $this->processToDigiflazz($order);
            }

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Midtrans Callback Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function processToDigiflazz(Order $order): void
    {
        try {
            if ($order->confirmed_at !== null) return;

            $target = $order->target_number . ($order->zone_id ?? '');
            $digiflazzService = app(\App\Services\DigiflazzService::class);
            $result = $digiflazzService->placeOrder($order->sku, $target, $order->order_id);

            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Digiflazz transaction failed');
            }

            $order->update(['confirmed_at' => now()]);
            $order->logStatusChange(OrderStatus::PROCESSING, 'Order sent to Digiflazz');

            // ✅ Notif Telegram ke admin — order dikirim ke Digiflazz
            TelegramService::notify("
📦 *ORDER DIKIRIM KE DIGIFLAZZ*
----------------------------------
*Order ID:* #{$order->order_id}
*Produk:* {$order->product_name}
*Target:* {$order->target_number}
*Nominal:* Rp " . number_format($order->total_price, 0, ',', '.') . "
----------------------------------
_Menunggu konfirmasi dari provider..._
            ");

        } catch (Exception $e) {
            Log::error('Digiflazz Error: ' . $e->getMessage());
            
            // JIKA GAGAL (MISAL SALDO 0), PAKSA STATUS JADI FAILED
            $order->update(['status' => OrderStatus::FAILED->value]);
            $order->logStatusChange(OrderStatus::FAILED, 'Auto-Fail: ' . $e->getMessage());
            
            $this->sendFailedEmail($order, $e->getMessage());
        }
    }

    private function sendFailedEmail(Order $order, ?string $rawReason = null): void
    {
        try {
            Mail::to($order->customer_email)->send(new OrderFailed($order, $rawReason));
        } catch (Exception $e) {
            Log::error('Mail Error: ' . $e->getMessage());
        }
    }
}