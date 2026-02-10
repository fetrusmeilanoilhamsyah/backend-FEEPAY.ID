<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsdtExchangeRequest;
use App\Http\Requests\ApproveUsdtRequest;
use App\Models\UsdtConversion;
use App\Enums\UsdtConversionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Exception;

class UsdtExchangeController extends Controller
{
    /**
     * Submit USDT exchange request (Public)
     * 
     * POST /api/usdt/submit
     */
    public function submit(StoreUsdtExchangeRequest $request)
    {
        try {
            DB::beginTransaction();

            // Handle file upload
            $proofPath = null;
            if ($request->hasFile('proof')) {
                $file = $request->file('proof');
                $filename = 'usdt_proof_' . time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
                $proofPath = $file->storeAs('usdt_proofs', $filename, 'public');
            }

            // Generate unique transaction ID
            $trxId = 'USDT' . strtoupper(Str::random(8)) . time();

            // Create conversion record
            $conversion = UsdtConversion::create([
                'trx_id' => $trxId,
                'amount' => $request->amount,
                'network' => $request->network,
                'idr_received' => $request->idr_received,
                'bank_details' => [
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'account_name' => $request->account_name,
                ],
                'proof_path' => $proofPath,
                'customer_email' => $request->customer_email,
                'customer_phone' => $request->customer_phone ?? null,
                'status' => 'pending',
            ]);

            DB::commit();

            Log::info('USDT exchange submitted', [
                'trx_id' => $trxId,
                'amount' => $request->amount,
                'network' => $request->network,
                'customer_email' => $request->customer_email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'USDT exchange request submitted successfully',
                'data' => [
                    'trx_id' => $conversion->trx_id,
                    'amount' => $conversion->amount,
                    'network' => $conversion->network,
                    'idr_received' => $conversion->idr_received,
                    'status' => 'pending',
                    'created_at' => $conversion->created_at->toIso8601String(),
                ],
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            // Clean up uploaded file if exists
            if (isset($proofPath) && $proofPath) {
                Storage::disk('public')->delete($proofPath);
            }

            Log::error('USDT exchange submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit exchange request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve/Reject USDT exchange (Admin only)
     * 
     * POST /api/admin/x7k2m/usdt/{id}/approve
     * 
     * FORCE UPDATE - No status check
     * SEND EMAIL - Notify user of approval/rejection
     */
    public function approve(ApproveUsdtRequest $request, int $id)
    {
        try {
            DB::beginTransaction();

            $conversion = UsdtConversion::find($id);

            if (!$conversion) {
                return response()->json([
                    'success' => false,
                    'message' => 'USDT conversion not found',
                ], 404);
            }

            // REMOVED STRICT STATUS CHECK - Allow force update
            // Admin can change status anytime

            $oldStatus = $conversion->status;

            // Update status with audit trail
            $conversion->update([
                'status' => $request->status,
                'admin_note' => $request->admin_note,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            // Send email notification
            if ($conversion->customer_email) {
                try {
                    $subject = $request->status === 'approved' 
                        ? 'USDT Exchange Approved - FEEPAY.ID'
                        : 'USDT Exchange Rejected - FEEPAY.ID';

                    $message = $this->buildEmailMessage($conversion, $request->status, $request->admin_note);

                Mail::queue($message, function ($mail) use ($conversion, $subject) {
                        $mail->to($conversion->customer_email)
                             ->subject($subject)
                             ->from(config('mail.from.address'), 'FEEPAY.ID');
                    });

                    Log::info('USDT email sent', [
                        'trx_id' => $conversion->trx_id,
                        'email' => $conversion->customer_email,
                        'status' => $request->status,
                    ]);

                } catch (Exception $emailError) {
                    Log::error('Failed to send USDT email', [
                        'trx_id' => $conversion->trx_id,
                        'error' => $emailError->getMessage(),
                    ]);
                    // Don't fail the entire operation if email fails
                }
            }

            DB::commit();

            Log::info('USDT conversion status updated', [
                'trx_id' => $conversion->trx_id,
                'old_status' => $oldStatus,
                'new_status' => $request->status,
                'approved_by' => $request->user()->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => "USDT conversion {$request->status} successfully",
                'data' => [
                    'trx_id' => $conversion->trx_id,
                    'status' => $conversion->status,
                    'amount' => $conversion->amount,
                    'network' => $conversion->network,
                    'idr_received' => $conversion->idr_received,
                    'bank_details' => $conversion->bank_details,
                    'admin_note' => $conversion->admin_note,
                    'approved_by' => $conversion->approvedBy->name,
                    'approved_at' => $conversion->approved_at->toIso8601String(),
                ],
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('USDT approval failed', [
                'conversion_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process approval: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build email message for USDT approval/rejection
     */
    private function buildEmailMessage(UsdtConversion $conversion, string $status, ?string $adminNote): string
    {
        $statusText = $status === 'approved' ? 'APPROVED' : 'REJECTED';
        $bankDetails = $conversion->bank_details;

        $message = "Dear Customer,\n\n";
        $message .= "Your USDT exchange request has been {$statusText}.\n\n";
        $message .= "Transaction Details:\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "Transaction ID: {$conversion->trx_id}\n";
        $message .= "Amount: {$conversion->amount} USDT\n";
        $message .= "Network: {$conversion->network}\n";
        $message .= "IDR Received: Rp " . number_format($conversion->idr_received, 0, ',', '.') . "\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        $message .= "Bank Account Details:\n";
        $message .= "Bank: {$bankDetails['bank_name']}\n";
        $message .= "Account Number: {$bankDetails['account_number']}\n";
        $message .= "Account Name: {$bankDetails['account_name']}\n\n";

        if ($adminNote) {
            $message .= "Admin Note:\n{$adminNote}\n\n";
        }

        if ($status === 'approved') {
            $message .= "Your IDR will be transferred to your bank account shortly.\n";
        } else {
            $message .= "Please contact support if you have any questions.\n";
        }

        $message .= "\nThank you for using FEEPAY.ID\n\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "FEEPAY.ID - Digital Marketplace & USDT Exchange\n";
        $message .= "This is an automated message. Please do not reply.";

        return $message;
    }

    /**
     * Get all USDT conversions (Admin only)
     * 
     * GET /api/admin/x7k2m/usdt
     */
    public function index()
    {
        try {
            $conversions = UsdtConversion::with('approvedBy')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($conversion) {
                    return [
                        'id' => $conversion->id,
                        'trx_id' => $conversion->trx_id,
                        'amount' => $conversion->amount,
                        'network' => $conversion->network,
                        'idr_received' => $conversion->idr_received,
                        'bank_details' => $conversion->bank_details, // Decoded array
                        'proof_path' => $conversion->proof_path,
                        'proof_url' => $conversion->proof_path ? asset('storage/' . $conversion->proof_path) : null,
                        'customer_email' => $conversion->customer_email,
                        'customer_phone' => $conversion->customer_phone,
                        'status' => $conversion->status,
                        'admin_note' => $conversion->admin_note,
                        'approved_by' => $conversion->approvedBy ? [
                            'id' => $conversion->approvedBy->id,
                            'name' => $conversion->approvedBy->name,
                            'email' => $conversion->approvedBy->email,
                        ] : null,
                        'approved_at' => $conversion->approved_at?->toIso8601String(),
                        'created_at' => $conversion->created_at->toIso8601String(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $conversions,
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch USDT conversions', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch conversions',
            ], 500);
        }
    }

    /**
     * Get single USDT conversion details
     * 
     * GET /api/usdt/{trxId}
     */
    public function show(string $trxId)
    {
        try {
            $conversion = UsdtConversion::where('trx_id', $trxId)->first();

            if (!$conversion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversion not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'trx_id' => $conversion->trx_id,
                    'amount' => $conversion->amount,
                    'network' => $conversion->network,
                    'idr_received' => $conversion->idr_received,
                    'status' => $conversion->status,
                    'bank_details' => $conversion->bank_details,
                    'proof_url' => $conversion->proof_path ? asset('storage/' . $conversion->proof_path) : null,
                    'created_at' => $conversion->created_at->toIso8601String(),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error('Failed to fetch USDT conversion', [
                'trx_id' => $trxId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch conversion',
            ], 500);
        }
    }
}