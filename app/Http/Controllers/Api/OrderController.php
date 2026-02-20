<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\ConfirmOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use App\Mail\OrderSuccess;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Exception;

class OrderController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * Store a new order (Guest checkout)
     */
    public function store(StoreOrderRequest $request)
    {
        try {
            // ✅ FIX M-03: Idempotency check — cegah double order dari spam klik
            // Frontend kirim X-Idempotency-Key di setiap request (sudah ada di api.js)
            $idempotencyKey = $request->header('X-Idempotency-Key');
            if ($idempotencyKey) {
                $cacheKey = 'idempotency:order:' . $idempotencyKey;
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    // Request duplikat — return order yang sama, jangan buat baru
                    Log::info('Duplicate order request blocked', [
                        'idempotency_key' => $idempotencyKey,
                        'ip' => $request->ip(),
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => 'Order created',
                        'data' => $cached,
                    ], 201);
                }
            }

            DB::beginTransaction();

            $priceResponse = $this->digiflazzService->getProductPrice($request->sku);

            if (!$priceResponse['success']) {
                return response()->json(['success' => false, 'message' => 'SKU tidak ditemukan'], 400);
            }

            $productData = $priceResponse['data'];
            $costPrice = $productData['price'] ?? 0;
            $sellingPrice = $costPrice + config('feepay.margin', 1000);

            $product = Product::updateOrCreate(
                ['sku' => $request->sku],
                [
                    'name' => $productData['product_name'] ?? 'Unknown Product',
                    'category' => $productData['category'] ?? 'General',
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                ]
            );

            $order = Order::create([
                'order_id' => 'FP' . strtoupper(Str::random(8)) . time(),
                'sku' => $request->sku,
                'product_name' => $product->name,
                'target_number' => $request->target_number,
                'customer_email' => $request->customer_email,
                'total_price' => $sellingPrice,
                'status' => OrderStatus::PENDING->value,
            ]);

            DB::commit();

            // ✅ FIX M-03: Simpan ke cache 24 jam — request duplikat return order yang sama
            if ($idempotencyKey) {
                Cache::put($cacheKey, $order, now()->addHours(24));
            }

            Log::info('Order Created', ['order_id' => $order->order_id]);

            return response()->json(['success' => true, 'message' => 'Order created', 'data' => $order], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Order Store Failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem'], 500);
        }
    }

    /**
     * Confirm order & Auto-order Digiflazz (Admin)
     */
    public function confirm(ConfirmOrderRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $order = Order::with('payment')->lockForUpdate()->find($id);

            if (!$order) return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            
            if ($order->status !== OrderStatus::PENDING->value) {
                return response()->json(['success' => false, 'message' => 'Order already processed'], 400);
            }

            $digiflazz = $this->digiflazzService->placeOrder($order->sku, $order->target_number, $order->order_id);

            if (!$digiflazz['success']) {
                $order->update(['status' => OrderStatus::FAILED->value]);
                $order->logStatusChange(OrderStatus::FAILED, 'Digiflazz Error: ' . $digiflazz['message'], $request->user()->id);
                DB::commit();
                return response()->json(['success' => false, 'message' => $digiflazz['message']], 400);
            }

            $apiData = $digiflazz['data'];

            $order->update([
                'status' => OrderStatus::PROCESSING->value,
                'sn' => $apiData['sn'] ?? '-',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            $order->logStatusChange(OrderStatus::PROCESSING, 'Order submitted to Digiflazz', $request->user()->id);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Order processed to Digiflazz', 'sn' => $order->sn], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Confirm Order Failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem'], 500);
        }
    }

    /**
     * Sinkronisasi Status dengan Digiflazz API
     */
    public function syncStatus($orderId)
    {
        try {
            $order = Order::where('order_id', $orderId)->firstOrFail();
            
            $result = $this->digiflazzService->checkOrderStatus($order->order_id);

            if ($result['success']) {
                $apiData = $result['data'];
                $digiStatus = strtolower($apiData['status'] ?? '');

                $newStatus = match($digiStatus) {
                    'sukses' => OrderStatus::SUCCESS,
                    'gagal' => OrderStatus::FAILED,
                    default => OrderStatus::PROCESSING,
                };

                if ($order->status !== $newStatus->value) {
                    $order->update([
                        'status' => $newStatus->value,
                        'sn' => $apiData['sn'] ?? $order->sn
                    ]);
                    
                    $order->logStatusChange($newStatus, "Sync Status: " . ($apiData['message'] ?? 'Synced'));

                    if ($newStatus === OrderStatus::SUCCESS) {
                        try {
                            $product = Product::where('sku', $order->sku)->first();
                            Mail::to($order->customer_email)->send(new OrderSuccess($order, $product));
                        } catch (Exception $mailEx) {
                            Log::error('Email Sync Failed', ['error' => $mailEx->getMessage()]);
                        }
                    }
                }

                return response()->json(['success' => true, 'message' => 'Status Updated to ' . $newStatus->value, 'status' => $newStatus->value]);
            }
            
            return response()->json(['success' => false, 'message' => $result['message']], 400);

        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan sistem'], 500);
        }
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => Order::orderBy('created_at', 'desc')->paginate(50)]);
    }

    /**
     * Show order by ID
     * ✅ FIX H-06: Verifikasi email sebelum return data order
     */
    public function show(Request $request, string $orderId)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $order = Order::where('order_id', $orderId)->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        if (strtolower($request->email) !== strtolower($order->customer_email)) {
            Log::warning('IDOR attempt - wrong email for order', [
                'order_id' => $orderId,
                'ip' => $request->ip(),
            ]);
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $order]);
    }
}