<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomerOrderController extends Controller
{
    /**
     * GET /api/customer/orders
     * Ambil semua order milik user yang sedang login.
     * Diurutkan dari terbaru, max 100.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user   = $request->user();
            $orders = Order::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get([
                    'order_id', 'sku', 'product_name', 'target_number', 'zone_id',
                    'customer_email', 'total_price', 'status', 'sn',
                    'midtrans_payment_type', 'midtrans_transaction_id',
                    'created_at', 'updated_at',
                ]);

            return response()->json([
                'success' => true,
                'data'    => $orders,
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerOrderController::index error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil riwayat transaksi.'], 500);
        }
    }

    /**
     * POST /api/customer/orders/claim
     * Klaim order milik guest (by email) ke akun user yang sedang login.
     * Dipanggil saat user baru saja login dan punya riwayat order sebagai guest.
     */
    public function claimOrders(Request $request): JsonResponse
    {
        $request->validate(['order_ids' => 'required|array|max:50', 'order_ids.*' => 'string']);

        try {
            $user = $request->user();

            // Claim order yang:
            // 1. Belum punya user_id (guest order)
            // 2. email cocok dengan user yang login
            // 3. order_id ada dalam daftar yang dikirim frontend
            $claimed = Order::whereIn('order_id', $request->order_ids)
                ->whereNull('user_id')
                ->where('customer_email', $user->email)
                ->update(['user_id' => $user->id]);

            // Juga claim berdasarkan phone jika user punya phone
            if ($user->phone) {
                $phoneTrimmed = preg_replace('/[^0-9]/', '', $user->phone);
                // Cari order yang customer_email berupa nomor HP ini
                Order::whereIn('order_id', $request->order_ids)
                    ->whereNull('user_id')
                    ->where(function ($q) use ($phoneTrimmed, $user) {
                        $q->where('customer_email', 'like', '%' . $phoneTrimmed . '%')
                          ->orWhere('customer_email', $user->phone);
                    })
                    ->update(['user_id' => $user->id]);
            }

            return response()->json([
                'success' => true,
                'claimed' => $claimed,
                'message' => "{$claimed} order berhasil dikaitkan ke akun Anda.",
            ]);
        } catch (\Exception $e) {
            Log::error('CustomerOrderController::claimOrders error', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengklaim order.'], 500);
        }
    }
}
