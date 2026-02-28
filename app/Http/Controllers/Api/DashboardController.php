<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics (Admin only)
     */
    public function stats(Request $request)
    {
        try {
            // Filter rentang tanggal
            $startDate = $request->input('start_date', now()->startOfMonth());
            $endDate = $request->input('end_date', now());

            // Statistik Pesanan
            $totalOrders = Order::whereBetween('created_at', [$startDate, $endDate])->count();
            $pendingOrders = Order::pending()->count();
            $successOrders = Order::success()->whereBetween('created_at', [$startDate, $endDate])->count();
            $failedOrders = Order::failed()->whereBetween('created_at', [$startDate, $endDate])->count();

            // Pendapatan dari pesanan sukses
            $totalRevenue = Order::success()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total_price');

            // Statistik Pembayaran
            $pendingPayments = Payment::where('status', 'pending')->count();
            $verifiedPayments = Payment::where('status', 'success')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();

            // Aktivitas Terbaru
            $recentOrders = Order::orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Grafik pendapatan harian (7 hari terakhir)
            $dailyRevenue = Order::success()
                ->where('created_at', '>=', now()->subDays(7))
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_price) as revenue'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_orders' => $totalOrders,
                        'pending_orders' => $pendingOrders,
                        'success_orders' => $successOrders,
                        'failed_orders' => $failedOrders,
                        'total_revenue' => (float) $totalRevenue,
                        'pending_payments' => $pendingPayments,
                        'verified_payments' => $verifiedPayments,
                        'total_products' => Product::count(),
                    ],
                    'recent_orders' => $recentOrders,
                    'daily_revenue' => $dailyRevenue,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Dashboard Stats Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil statistik'], 500);
        }
    }

    /**
     * Get Digiflazz account balance
     */
    public function getBalance()
    {
        try {
            $digiflazzService = app(\App\Services\DigiflazzService::class);
            $result = $digiflazzService->checkBalance(); // Sesuaikan nama method di service kamu

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal ambil saldo: ' . ($result['message'] ?? 'Unknown error'),
                ], 400);
            }

            $balance = $result['data']['deposit'] ?? 0;
            $isLow = $balance < 50000;

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'balance_formatted' => 'Rp ' . number_format($balance, 0, ',', '.'),
                    'is_low' => $isLow,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Digiflazz Balance Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal cek saldo'], 500);
        }
    }
}
