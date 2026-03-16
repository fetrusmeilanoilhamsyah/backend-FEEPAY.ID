<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    /**
     * GET /api/admin/{path}/dashboard/stats
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date', now()->startOfMonth()->toDateTimeString());
            $endDate   = $request->input('end_date', now()->toDateTimeString());

            $totalOrders   = Order::whereBetween('created_at', [$startDate, $endDate])->count();
            $pendingOrders = Order::pending()->count();
            $successOrders = Order::success()->whereBetween('created_at', [$startDate, $endDate])->count();
            $failedOrders  = Order::failed()->whereBetween('created_at', [$startDate, $endDate])->count();
            $totalRevenue  = Order::success()
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total_price');

            $recentOrders = Order::orderBy('created_at', 'desc')->limit(10)->get();

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
                'data'    => [
                    'overview' => [
                        'total_orders'   => $totalOrders,
                        'pending_orders' => $pendingOrders,
                        'success_orders' => $successOrders,
                        'failed_orders'  => $failedOrders,
                        'total_revenue'  => (float) $totalRevenue,
                        'total_products' => Product::count(),
                        'total_users'    => \App\Models\User::count(),
                    ],
                    'recent_orders' => $recentOrders,
                    'daily_revenue' => $dailyRevenue,
                    'date_range'    => [
                        'start' => $startDate,
                        'end'   => $endDate,
                    ],
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('DashboardController::stats gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil statistik.'], 500);
        }
    }

    /**
     * GET /api/admin/{path}/dashboard/balance
     */
    public function getBalance(): JsonResponse
    {
        try {
            $result = $this->digiflazzService->getBalance();

            if (empty($result['data']['deposit']) && ($result['data']['deposit'] ?? null) !== 0) {
    return response()->json([
        'success' => false,
        'message' => 'Gagal ambil saldo dari Digiflazz.',
    ], 400);
}
            $balance = $result['data']['deposit'] ?? 0;
            $isLow   = $balance < 50000;

            return response()->json([
                'success' => true,
                'data'    => [
                    'balance'           => $balance,
                    'balance_formatted' => 'Rp ' . number_format($balance, 0, ',', '.'),
                    'is_low'            => $isLow,
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('DashboardController::getBalance gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal cek saldo.'], 500);
        }
    }

    /**
     * GET /api/admin/{path}/dashboard/products
     */
    public function productStats(): JsonResponse
    {
        try {
            $stats = Product::select('category', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active'))
                ->groupBy('category')
                ->get();

            return response()->json(['success' => true, 'data' => $stats], 200);

        } catch (\Exception $e) {
            Log::error('DashboardController::productStats gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil statistik produk.'], 500);
        }
    }
}
