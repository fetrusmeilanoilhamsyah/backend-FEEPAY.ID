<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendOrderSuccessEmail;
use App\Jobs\SendOrderFailedEmail;

class CallbackController extends Controller
{
    /**
     * Handle Digiflazz callback
     * 
     * CRITICAL FIXES:
     * - Added lockForUpdate() to prevent race condition
     * - Moved email sending to queue jobs
     * - Added idempotency check
     * - Better error handling
     */
    public function digiflazz(Request $request)
    {
        // Validate signature
        $secret = config('services.digiflazz.webhook_secret');
        $signature = $request->header('X-Digiflazz-Signature');
        
        if (empty($signature)) {
            Log::warning('Digiflazz callback: Missing signature', [
                'ip' => $request->ip(),
                'payload' => $request->all()
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Digiflazz callback: Invalid signature', [
                'ip' => $request->ip(),
                'expected' => $expectedSignature,
                'received' => $signature
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // Validate content type
        if ($request->header('Content-Type') !== 'application/json') {
            Log::warning('Digiflazz callback: Invalid content type', [
                'content_type' => $request->header('Content-Type')
            ]);
            return response()->json(['message' => 'Invalid content type'], 400);
        }

        $data = $request->all();
        
        // Validate required fields
        if (empty($data['ref_id']) || empty($data['status'])) {
            Log::error('Digiflazz callback: Missing required fields', ['data' => $data]);
            return response()->json(['message' => 'Missing required fields'], 400);
        }

        DB::beginTransaction();
        try {
            // ✅ CRITICAL FIX: Pessimistic locking untuk mencegah race condition
            $order = Order::where('trx_id', $data['ref_id'])
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                Log::warning('Digiflazz callback: Order not found', [
                    'ref_id' => $data['ref_id']
                ]);
                return response()->json(['message' => 'Order not found'], 404);
            }

            // ✅ IDEMPOTENCY: Cek apakah sudah diproses
            if (in_array($order->status, ['success', 'failed'])) {
                DB::rollBack();
                Log::info('Digiflazz callback: Order already processed', [
                    'ref_id' => $data['ref_id'],
                    'status' => $order->status
                ]);
                return response()->json(['message' => 'Already processed'], 200);
            }

            $previousStatus = $order->status;

            // Update order based on callback status
            if ($data['status'] === 'Sukses') {
                $order->status = 'success';
                $order->sn = $data['sn'] ?? null;
                $order->provider_response = json_encode($data);
                $order->save();

                DB::commit();

                // ✅ PERFORMANCE FIX: Kirim email via queue (non-blocking)
                SendOrderSuccessEmail::dispatch($order);

                Log::info('Digiflazz callback: Order success', [
                    'ref_id' => $data['ref_id'],
                    'order_id' => $order->id
                ]);

            } elseif ($data['status'] === 'Gagal') {
                $order->status = 'failed';
                $order->provider_response = json_encode($data);
                $order->save();

                // ✅ CRITICAL FIX: Refund balance atomically
                if ($previousStatus === 'pending' || $previousStatus === 'processing') {
                    DB::table('users')
                        ->where('id', $order->user_id)
                        ->update([
                            'balance' => DB::raw('balance + ' . $order->total_price),
                            'updated_at' => now()
                        ]);
                }

                DB::commit();

                // ✅ PERFORMANCE FIX: Kirim email via queue
                SendOrderFailedEmail::dispatch($order);

                Log::info('Digiflazz callback: Order failed and balance refunded', [
                    'ref_id' => $data['ref_id'],
                    'order_id' => $order->id,
                    'refund_amount' => $order->total_price
                ]);

            } else {
                // Status lain (processing, pending, dll)
                $order->status = strtolower($data['status']);
                $order->provider_response = json_encode($data);
                $order->save();

                DB::commit();

                Log::info('Digiflazz callback: Order status updated', [
                    'ref_id' => $data['ref_id'],
                    'status' => $data['status']
                ]);
            }

            return response()->json(['message' => 'Callback processed successfully'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Digiflazz callback error', [
                'ref_id' => $data['ref_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * Handle Midtrans callback
     */
    public function midtrans(Request $request)
    {
        // Validate signature
        $serverKey = config('services.midtrans.server_key');
        
        $orderId = $request->input('order_id');
        $statusCode = $request->input('status_code');
        $grossAmount = $request->input('gross_amount');
        $signatureKey = $request->input('signature_key');

        $expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);

        if ($signatureKey !== $expectedSignature) {
            Log::warning('Midtrans callback: Invalid signature', [
                'ip' => $request->ip(),
                'order_id' => $orderId
            ]);
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        // Validate content type
        if (!in_array($request->header('Content-Type'), ['application/json', 'application/x-www-form-urlencoded'])) {
            Log::warning('Midtrans callback: Invalid content type', [
                'content_type' => $request->header('Content-Type')
            ]);
            return response()->json(['message' => 'Invalid content type'], 400);
        }

        DB::beginTransaction();
        try {
            // ✅ CRITICAL FIX: Pessimistic locking
            $order = Order::where('trx_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                DB::rollBack();
                Log::warning('Midtrans callback: Order not found', ['order_id' => $orderId]);
                return response()->json(['message' => 'Order not found'], 404);
            }

            // ✅ IDEMPOTENCY: Cek apakah sudah diproses
            if ($order->payment_status === 'paid') {
                DB::rollBack();
                Log::info('Midtrans callback: Payment already processed', [
                    'order_id' => $orderId
                ]);
                return response()->json(['message' => 'Already processed'], 200);
            }

            $transactionStatus = $request->input('transaction_status');
            $fraudStatus = $request->input('fraud_status');

            if ($transactionStatus === 'capture') {
                if ($fraudStatus === 'accept') {
                    $order->payment_status = 'paid';
                }
            } elseif ($transactionStatus === 'settlement') {
                $order->payment_status = 'paid';
            } elseif (in_array($transactionStatus, ['cancel', 'deny', 'expire'])) {
                $order->payment_status = 'failed';
            } elseif ($transactionStatus === 'pending') {
                $order->payment_status = 'pending';
            }

            $order->midtrans_response = json_encode($request->all());
            $order->save();

            DB::commit();

            Log::info('Midtrans callback processed', [
                'order_id' => $orderId,
                'payment_status' => $order->payment_status,
                'transaction_status' => $transactionStatus
            ]);

            return response()->json(['message' => 'Callback processed'], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Midtrans callback error', [
                'order_id' => $orderId ?? null,
                'error' => $e->getMessage()
            ]);

            return response()->json(['message' => 'Internal server error'], 500);
        }
    }
}