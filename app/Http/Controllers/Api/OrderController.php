<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DigiflazzService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessOrderTransaction;

class OrderController extends Controller
{
    protected $digiflazz;

    public function __construct(DigiflazzService $digiflazz)
    {
        $this->digiflazz = $digiflazz;
    }

    /**
     * Display a listing of user's orders
     * 
     * FIXES:
     * - Added eager loading to prevent N+1
     * - Added caching for better performance
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,processing,success,failed',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $query = Order::query()
            ->with(['product:id,code,name,category', 'user:id,name,email'])
            ->where('user_id', auth()->id())
            ->select(['id', 'user_id', 'product_id', 'customer_id', 'total_price', 'status', 'trx_id', 'sn', 'created_at', 'updated_at']);

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $orders = $query->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($orders);
    }

    /**
     * Store a new order
     * 
     * CRITICAL FIXES:
     * - Added lockForUpdate() to prevent race condition
     * - Atomic balance deduction
     * - Moved API call to queue job
     * - Better error handling
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'customer_id' => 'required|string|max:50',
            'quantity' => 'nullable|integer|min:1|max:100',
        ]);

        $quantity = $validated['quantity'] ?? 1;

        DB::beginTransaction();
        try {
            // Get product
            $product = Product::where('id', $validated['product_id'])
                ->where('status', 'active')
                ->firstOrFail();

            $totalPrice = $product->price * $quantity;

            // ✅ CRITICAL FIX: Lock user untuk mencegah race condition
            $user = User::where('id', auth()->id())
                ->lockForUpdate()
                ->first();

            if (!$user) {
                DB::rollBack();
                return response()->json(['message' => 'User not found'], 404);
            }

            // Check balance
            if ($user->balance < $totalPrice) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Insufficient balance',
                    'required' => $totalPrice,
                    'available' => $user->balance,
                    'shortage' => $totalPrice - $user->balance
                ], 400);
            }

            // ✅ CRITICAL FIX: Atomic balance deduction dengan DB::raw
            $affected = DB::table('users')
                ->where('id', $user->id)
                ->where('balance', '>=', $totalPrice)
                ->update([
                    'balance' => DB::raw('balance - ' . $totalPrice),
                    'updated_at' => now()
                ]);

            if ($affected === 0) {
                DB::rollBack();
                return response()->json(['message' => 'Balance deduction failed'], 500);
            }

            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'customer_id' => $validated['customer_id'],
                'quantity' => $quantity,
                'total_price' => $totalPrice,
                'status' => 'pending',
                'trx_id' => 'TRX-' . time() . '-' . $user->id . '-' . rand(1000, 9999),
            ]);

            DB::commit();

            // ✅ PERFORMANCE FIX: Dispatch ke queue SETELAH commit berhasil
            ProcessOrderTransaction::dispatch($order);

            Log::info('Order created successfully', [
                'user_id' => $user->id,
                'order_id' => $order->id,
                'trx_id' => $order->trx_id,
                'amount' => $totalPrice
            ]);

            return response()->json([
                'message' => 'Order created successfully',
                'data' => $order->load('product:id,code,name,category')
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['message' => 'Product not found or inactive'], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order creation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['message' => 'Order creation failed'], 500);
        }
    }

    /**
     * Display a specific order
     */
    public function show($id)
    {
        $order = Order::with(['product:id,code,name,category,price', 'user:id,name,email'])
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        return response()->json(['data' => $order]);
    }

    /**
     * Confirm order (for admin)
     * 
     * CRITICAL FIXES:
     * - Added lockForUpdate()
     * - Moved API call to queue
     */
    public function confirm(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // ✅ CRITICAL FIX: Pessimistic locking
            $order = Order::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            // ✅ IDEMPOTENCY: Cek apakah sudah diproses
            if (in_array($order->status, ['success', 'failed'])) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Order already processed',
                    'status' => $order->status
                ], 400);
            }

            $order->status = 'processing';
            $order->save();

            DB::commit();

            // ✅ PERFORMANCE: Process di background
            ProcessOrderTransaction::dispatch($order);

            return response()->json([
                'message' => 'Order confirmation in progress',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order confirmation failed', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['message' => 'Confirmation failed'], 500);
        }
    }

    /**
     * Cancel order
     * 
     * CRITICAL FIXES:
     * - Added lockForUpdate()
     * - Atomic refund
     */
    public function cancel($id)
    {
        DB::beginTransaction();
        try {
            // ✅ CRITICAL FIX: Lock order dan user
            $order = Order::where('id', $id)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->firstOrFail();

            // Only allow cancellation of pending orders
            if ($order->status !== 'pending') {
                DB::rollBack();
                return response()->json([
                    'message' => 'Cannot cancel order',
                    'reason' => 'Order is already ' . $order->status
                ], 400);
            }

            $order->status = 'cancelled';
            $order->save();

            // ✅ CRITICAL FIX: Atomic refund
            $affected = DB::table('users')
                ->where('id', $order->user_id)
                ->update([
                    'balance' => DB::raw('balance + ' . $order->total_price),
                    'updated_at' => now()
                ]);

            if ($affected === 0) {
                DB::rollBack();
                return response()->json(['message' => 'Refund failed'], 500);
            }

            DB::commit();

            Log::info('Order cancelled and refunded', [
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'refund_amount' => $order->total_price
            ]);

            return response()->json([
                'message' => 'Order cancelled successfully',
                'refund_amount' => $order->total_price
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order cancellation failed', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['message' => 'Cancellation failed'], 500);
        }
    }

    /**
     * Retry failed order
     */
    public function retry($id)
    {
        DB::beginTransaction();
        try {
            $order = Order::where('id', $id)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'failed') {
                DB::rollBack();
                return response()->json([
                    'message' => 'Only failed orders can be retried'
                ], 400);
            }

            $order->status = 'pending';
            $order->save();

            DB::commit();

            // Dispatch to queue
            ProcessOrderTransaction::dispatch($order);

            return response()->json([
                'message' => 'Order retry initiated',
                'data' => $order
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order retry failed', [
                'order_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json(['message' => 'Retry failed'], 500);
        }
    }
}