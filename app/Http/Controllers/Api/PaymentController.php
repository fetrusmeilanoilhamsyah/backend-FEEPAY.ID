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

                    // Log status change
                    $order->logStatusChange(
                        OrderStatus::PROCESSING, 
                        'Auto-processed after payment verified', 
                        $request->user()->id
                    );
                    
                    // ✅ SECURITY: Log admin action
                    Log::info('Payment verified and order processed', [
                        'payment_id' => $payment->payment_id,
                        'order_id' => $order->order_id,
                        'admin_id' => $request->user()->id,
                        'digiflazz_sn' => $apiData['sn'] ?? '-',
                    ]);
                } else {
                    // Jika GAGAL (Saldo habis, dll)
                    $order->update(['status' => 'failed']);
                    $order->logStatusChange(
                        OrderStatus::FAILED, 
                        'Digiflazz Error: ' . $digiflazzResponse['message'], 
                        $request->user()->id
                    );
                    $finalMessage = "Verified but Digiflazz Failed: " . $digiflazzResponse['message'];
                    
                    // ✅ SECURITY: Log failure
                    Log::error('Digiflazz processing failed after payment verification', [
                        'payment_id' => $payment->payment_id,
                        'order_id' => $order->order_id,
                        'error' => $digiflazzResponse['message'],
                    ]);
                }
            } else {
                // Jika REJECTED oleh Admin
                $order->update(['status' => 'failed']);
                $order->logStatusChange(
                    OrderStatus::FAILED, 
                    'Payment rejected by admin', 
                    $request->user()->id
                );
                
                // ✅ SECURITY: Log rejection
                Log::info('Payment rejected by admin', [
                    'payment_id' => $payment->payment_id,
                    'order_id' => $order->order_id,
                    'admin_id' => $request->user()->id,
                    'reason' => $request->admin_note,
                ]);
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
            
            Log::error('Payment verification failed', [
                'payment_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
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
            Log::error('Failed to fetch payments', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit payment proof (User)
     * ✅ SECURITY IMPROVEMENTS:
     * - Strict file validation (mimes, size)
     * - Random filename (prevent directory traversal)
     * - Private storage (not public accessible)
     * - Enhanced logging
     */
    public function submit(StorePaymentRequest $request)
    {
        try {
            // ✅ SECURITY: Additional file validation
            $request->validate([
                'order_id' => 'required|string|exists:orders,order_id',
                'type' => 'required|string|in:bank_transfer,qris',
                'amount' => 'required|numeric|min:1',
                'proof' => [
                    'required',
                    'file',
                    'mimes:jpg,jpeg,png,pdf',  // ✅ Whitelist only safe formats
                    'max:5120',  // ✅ 5MB max
                ],
            ]);
            
            DB::beginTransaction();

            $order = Order::where('order_id', $request->order_id)->first();
            
            if (!$order || $order->payment_id) {
                // ✅ SECURITY: Log suspicious activity
                Log::warning('Invalid payment submission attempt', [
                    'order_id' => $request->order_id,
                    'ip' => $request->ip(),
                    'reason' => !$order ? 'order_not_found' : 'payment_exists',
                ]);
                
                return response()->json([
                    'success' => false, 
                    'message' => 'Invalid order or payment exists'
                ], 400);
            }

            $proofPath = null;
            if ($request->hasFile('proof')) {
                $file = $request->file('proof');
                
                // ✅ SECURITY: Generate completely random filename
                // JANGAN pakai time() atau filename asli untuk avoid predictability
                $filename = Str::random(40) . '.' . $file->extension();
                
                // ✅ SECURITY: Store di PRIVATE disk (bukan public!)
                // File tidak bisa diakses langsung via URL
                $proofPath = $file->storeAs('payment_proofs', $filename, 'private');
                
                // ✅ SECURITY: Log file upload
                Log::info('Payment proof uploaded', [
                    'order_id' => $request->order_id,
                    'filename' => $filename,
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                    'ip' => $request->ip(),
                ]);
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
            
            // ✅ SECURITY: Log successful submission
            Log::info('Payment submitted successfully', [
                'payment_id' => $payment->payment_id,
                'order_id' => $order->order_id,
                'amount' => $request->amount,
                'type' => $request->type,
            ]);
            
            return response()->json([
                'success' => true, 
                'message' => 'Success'
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            // ✅ SECURITY: Log validation failures
            Log::warning('Payment submission validation failed', [
                'order_id' => $request->order_id,
                'errors' => $e->errors(),
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // ✅ SECURITY: Log errors
            Log::error('Payment submission failed', [
                'order_id' => $request->order_id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to submit payment'
            ], 500);
        }
    }
    
    /**
     * ✅ OPTIONAL: Method untuk download payment proof (Admin only)
     * Karena file disimpan di private storage
     */
    public function downloadProof(int $id)
    {
        try {
            $payment = Payment::findOrFail($id);
            
            if (!$payment->proof_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'No proof file found'
                ], 404);
            }
            
            // Check if file exists
            if (!Storage::disk('private')->exists($payment->proof_path)) {
                Log::error('Payment proof file not found', [
                    'payment_id' => $payment->payment_id,
                    'path' => $payment->proof_path,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }
            
            // ✅ SECURITY: Log file access
            Log::info('Payment proof accessed', [
                'payment_id' => $payment->payment_id,
                'admin_id' => auth()->id(),
            ]);
            
            // Return file download
            return Storage::disk('private')->download($payment->proof_path);
            
        } catch (\Exception $e) {
            Log::error('Failed to download payment proof', [
                'payment_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to download file'
            ], 500);
        }
    }
}