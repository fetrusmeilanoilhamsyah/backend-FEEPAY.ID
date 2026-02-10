<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CallbackController extends Controller
{
    /**
     * Handle Digiflazz callback
     * 
     * POST /api/callback/digiflazz
     */
    public function digiflazz(Request $request)
    {
        try {
            // Log incoming callback
            Log::info('Digiflazz callback received', $request->all());

            // Validate callback signature
            $username = config('services.digiflazz.username');
            $apiKey = config('services.digiflazz.api_key');
            $refId = $request->input('data.ref_id');
            
            $expectedSign = md5($username . $apiKey . $refId);
            $receivedSign = $request->input('sign');

            if ($expectedSign !== $receivedSign) {
                Log::warning('Invalid Digiflazz callback signature', [
                    'expected' => $expectedSign,
                    'received' => $receivedSign,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 401);
            }

            // Find order by ref_id (order_id)
            $order = Order::where('order_id', $refId)->first();

            if (!$order) {
                Log::warning('Order not found in callback', [
                    'ref_id' => $refId,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Get callback data
            $status = $request->input('data.status');
            $sn = $request->input('data.sn');
            $message = $request->input('data.message');

            // Update order based on status
            if ($status === 'Sukses') {
                $order->update([
                    'status' => OrderStatus::SUCCESS->value,
                    'sn' => $sn,
                ]);

                $order->logStatusChange(
                    OrderStatus::SUCCESS,
                    'Order completed via Digiflazz callback'
                );

                Log::info('Order marked as success via callback', [
                    'order_id' => $order->order_id,
                    'sn' => $sn,
                ]);

            } elseif ($status === 'Gagal') {
                $order->update([
                    'status' => OrderStatus::FAILED->value,
                ]);

                $order->logStatusChange(
                    OrderStatus::FAILED,
                    'Order failed: ' . $message
                );

                Log::warning('Order marked as failed via callback', [
                    'order_id' => $order->order_id,
                    'message' => $message,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback processed',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Digiflazz callback processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Callback processing failed',
            ], 500);
        }
    }
}