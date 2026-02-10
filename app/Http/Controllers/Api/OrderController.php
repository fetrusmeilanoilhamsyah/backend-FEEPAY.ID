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
            DB::beginTransaction();

            // 1. Ambil Harga Modal dari Digiflazz (Pakai fungsi getProductPrice di Service Boss)
            $priceResponse = $this->digiflazzService->getProductPrice($request->sku);

            if (!$priceResponse['success']) {
                return response()->json(['success' => false, 'message' => 'SKU tidak ditemukan'], 400);
            }

            $productData = $priceResponse['data'];
            $costPrice = $productData['price'] ?? 0;
            
            // Hitung Harga Jual (Modal + Margin dari config)
            $sellingPrice = $costPrice + config('feepay.margin', 1000);

            // 2. Simpan/Update data produk ke database lokal
            $product = Product::updateOrCreate(
                ['sku' => $request->sku],
                [
                    'name' => $productData['product_name'] ?? 'Unknown Product',
                    'category' => $productData['category'] ?? 'General',
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                ]
            );

            // 3. Buat Data Order
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
            
            Log::info('Order Created', ['order_id' => $order->order_id]);

            return response()->json(['success' => true, 'message' => 'Order created', 'data' => $order], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Order Store Failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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

            // Tembak API Digiflazz
            $digiflazz = $this->digiflazzService->placeOrder($order->sku, $order->target_number, $order->order_id);

            if (!$digiflazz['success']) {
                $order->update(['status' => OrderStatus::FAILED->value]);
                $order->logStatusChange(OrderStatus::FAILED, 'Digiflazz Error: ' . $digiflazz['message'], $request->user()->id);
                DB::commit();
                return response()->json(['success' => false, 'message' => $digiflazz['message']], 400);
            }

            $apiData = $digiflazz['data'];

            // UPDATE: Gunakan Status PROCESSING (Biru) agar tidak error SQL Enum
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * [FITUR BARU] Sinkronisasi Status dengan Digiflazz API
     */
    public function syncStatus($orderId)
    {
        try {
            $order = Order::where('order_id', $orderId)->firstOrFail();
            
            // Panggil checkOrderStatus dari Service Boss
            $result = $this->digiflazzService->checkOrderStatus($order->order_id);

            if ($result['success']) {
                $apiData = $result['data'];
                $digiStatus = strtolower($apiData['status'] ?? '');

                // Map status dari Digiflazz ke Enum kita
                $newStatus = match($digiStatus) {
                    'sukses' => OrderStatus::SUCCESS,
                    'gagal' => OrderStatus::FAILED,
                    default => OrderStatus::PROCESSING,
                };

                // Jika status berubah, baru kita update DB
                if ($order->status !== $newStatus->value) {
                    $order->update([
                        'status' => $newStatus->value,
                        'sn' => $apiData['sn'] ?? $order->sn
                    ]);
                    
                    $order->logStatusChange($newStatus, "Sync Status: " . ($apiData['message'] ?? 'Synced'));

                    // Kirim Email HANYA jika status berubah jadi SUCCESS
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => Order::orderBy('created_at', 'desc')->paginate(50)]);
    }

    public function show(string $orderId)
    {
        $order = Order::where('order_id', $orderId)->first();
        return $order ? response()->json(['success' => true, 'data' => $order]) : response()->json(['success' => false], 404);
    }
}