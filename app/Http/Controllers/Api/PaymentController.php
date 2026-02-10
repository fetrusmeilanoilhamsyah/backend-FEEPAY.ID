<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Models\Order;
use App\Services\DigiflazzService;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected DigiflazzService $digiflazzService;

    public function __construct(DigiflazzService $digiflazzService)
    {
        $this->digiflazzService = $digiflazzService;
    }

    /**
     * Verify payment (Admin) - AUTO ORDER DIGIFLAZZ
     */
    public function verify(Request $request, int $id)
    {
        $request->validate([
            'status' => 'required|in:verified,rejected',
            'admin_note' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            // Lock record biar gak bentrok
            $payment = Payment::with('order')->lockForUpdate()->find($id);

            if (!$payment || !$payment->order) {
                return response()->json(['success' => false, 'message' => 'Payment or Order not found'], 404);
            }

            // 1. Update status payment
            $payment->update([
                'status' => $request->status,
                'admin_note' => $request->admin_note,
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);

            $order = $payment->order;
            $finalMessage = "Payment {$request->status} successfully";

            if ($request->status === 'verified') {
                // 2. Tembak API Digiflazz
                $digiflazzResponse = $this->digiflazzService->placeOrder(
                    $order->sku,
                    $order->target_number,
                    $order->order_id
                );

                if ($digiflazzResponse['success']) {
                    $apiData = $digiflazzResponse['data'];
                    
                    // Update Order ke Processing
                    $order->update([
                        'status' => 'processing',
                        'sn' => $apiData['sn'] ?? '-',
                        'confirmed_by' => $request->user()->id,
                        'confirmed_at' => now(),
                    ]);

                    // FIX: Pakai konstanta Enum PROCESSING (Huruf Besar)
                    $order->logStatusChange(OrderStatus::PROCESSING, 'Auto-processed after payment verified', $request->user()->id);
                } else {
                    // Jika GAGAL (Saldo habis, dll)
                    $order->update(['status' => 'failed']);
                    $order->logStatusChange(OrderStatus::FAILED, 'Digiflazz Error: ' . $digiflazzResponse['message'], $request->user()->id);
                    $finalMessage = "Verified but Digiflazz Failed: " . $digiflazzResponse['message'];
                }
            } else {
                // Jika REJECTED oleh Admin
                $order->update(['status' => 'failed']);
                $order->logStatusChange(OrderStatus::FAILED, 'Payment rejected by admin', $request->user()->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $finalMessage,
                'data' => [
                    'payment_id' => $payment->payment_id,
                    'status' => $payment->status,
                    'order_status' => $order->status,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Verification failed: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'System Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payments (Admin)
     */
    public function index(Request $request)
    {
        try {
            $query = Payment::with(['order', 'verifiedBy']);

            if ($request->status) {
                $query->where('status', $request->status);
            }

            $payments = $query->orderBy('created_at', 'desc')->get()->map(function ($p) {
                return [
                    'id' => $p->id,
                    'payment_id' => $p->payment_id,
                    'type' => $p->type,
                    'amount' => $p->amount,
                    'status' => $p->status,
                    'proof_url' => $p->proof_path ? asset('storage/' . $p->proof_path) : null,
                    'admin_note' => $p->admin_note,
                    'order' => $p->order ? [
                        'order_id' => $p->order->order_id,
                        'product_name' => $p->order->product_name,
                        'status' => $p->order->status,
                    ] : null,
                    'created_at' => $p->created_at->toIso8601String(),
                ];
            });

            return response()->json(['success' => true, 'data' => $payments]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit payment proof (User)
     */
    public function submit(StorePaymentRequest $request)
    {
        try {
            DB::beginTransaction();

            $order = Order::where('order_id', $request->order_id)->first();
            if (!$order || $order->payment_id) {
                return response()->json(['success' => false, 'message' => 'Invalid order or payment exists'], 400);
            }

            $proofPath = null;
            if ($request->hasFile('proof')) {
                $file = $request->file('proof');
                $filename = 'proof_' . time() . '_' . Str::random(5) . '.' . $file->getClientOriginalExtension();
                $proofPath = $file->storeAs('payment_proofs', $filename, 'public');
            }

            $payment = Payment::create([
                'payment_id' => 'PAY' . strtoupper(Str::random(5)) . time(),
                'type' => $request->type,
                'amount' => $request->amount,
                'proof_path' => $proofPath,
                'status' => 'pending',
            ]);

            $order->update(['payment_id' => $payment->id]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Success'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}