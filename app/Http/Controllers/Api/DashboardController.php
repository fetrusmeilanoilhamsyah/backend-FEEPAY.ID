<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\UsdtConversion;
use App\Models\Payment;
use App\Enums\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics (Admin only)
     * * GET /api/admin/x7k2m/dashboard/stats
     */
    public function stats(Request $request)
    {
        try {
            // Date range filter
            $startDate = $request->input('start_date', now()->startOfMonth());
            $endDate = $request->input('end_date', now());

            // Orders statistics
            $totalOrders = Order::whereBetween('created_at', [$startDate, $endDate])->count();
            $pendingOrders = Order::pending()->count();
            $successOrders = Order::success()->whereBetween('created_at', [$startDate, $endDate])->count();
            $failedOrders = Order::failed()->whereBetween('created_at', [$startDate, $endDate])->count();

            // Revenue from successful orders
            $totalRevenue = Order::success()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total_price');

            // USDT Conversions statistics
            $totalConversions = UsdtConversion::whereBetween('created_at', [$startDate, $endDate])->count();
            $pendingConversions = UsdtConversion::pending()->count();
            $totalUsdtAmount = UsdtConversion::where('status', 'approved')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount');

            // Payment statistics
            $pendingPayments = Payment::pending()->count();
            $verifiedPayments = Payment::verified()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();

            // Recent activities
            $recentOrders = Order::with(['payment', 'confirmedBy'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $recentConversions = UsdtConversion::with('approvedBy')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Daily revenue chart (last 7 days)
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
                        'total_conversions' => $totalConversions,
                        'pending_conversions' => $pendingConversions,
                        'total_usdt_amount' => (float) $totalUsdtAmount,
                        'pending_payments' => $pendingPayments,
                        'verified_payments' => $verifiedPayments,
                    ],
                    'recent_orders' => $recentOrders,
                    'recent_conversions' => $recentConversions,
                    'daily_revenue' => $dailyRevenue,
                    'date_range' => [
                        'start' => $startDate,
                        'end' => $endDate,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch dashboard stats', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
            ], 500);
        }
    }

    /**
     * Get product sales statistics (Admin only)
     * * GET /api/admin/x7k2m/dashboard/products
     */
    public function productStats()
    {
        try {
            $topProducts = Order::success()
                ->select(
                    'product_name',
                    'sku',
                    DB::raw('COUNT(*) as total_sales'),
                    DB::raw('SUM(total_price) as total_revenue')
                )
                ->groupBy('product_name', 'sku')
                ->orderBy('total_sales', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'top_products' => $topProducts,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch product stats', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product statistics',
            ], 500);
        }
    }

    /**
     * Get Digiflazz account balance
     * Dipanggil dari admin dashboard untuk monitor saldo
     * Saldo penting: kalau habis → semua order GAGAL otomatis
     * * GET /api/admin/x7k2m/dashboard/balance
     */
    public function getBalance()
    {
        try {
            $digiflazzService = app(\App\Services\DigiflazzService::class);
            $result = $digiflazzService->getBalance();

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal ambil saldo: ' . $result['message'],
                ], 400);
            }

            $balance = $result['data']['deposit'] ?? 0;

            // Warning kalau saldo dibawah threshold
            // Ubah angka 50000 sesuai kebutuhan kamu
            $isLow = $balance < 50000;

            Log::info('Digiflazz balance checked by admin', [
                'balance' => $balance,
                'is_low' => $isLow,
                'admin_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => $balance,
                    'balance_formatted' => 'Rp ' . number_format($balance, 0, ',', '.'),
                    'is_low' => $isLow,
                    'warning_threshold' => 50000,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to get Digiflazz balance', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal cek saldo: ' . $e->getMessage(),
            ], 500);
        }
    }
}