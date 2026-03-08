<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Services\DigiflazzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(
        protected DigiflazzService $digiflazzService
    ) {}

    public function stats(Request $request): JsonResponse
    {
        try {
            $startDate = $request->input('start_date', now()->startOfMonth()->toDateTimeString());
            $endDate   = $request->input('end_date', now()->toDateTimeString());

            // ✅ PERBAIKAN: Single query dengan conditional aggregation + cache
            $stats = Cache::remember(
                "dashboard:stats:{$startDate}:{$endDate}",
                now()->addMinutes(5),
                function () use ($startDate, $endDate) {
                    $overview = Order::query()
                        ->selectRaw("
                            COUNT(*) as total_orders,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_orders,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders,
                            SUM(CASE WHEN status = 'success' THEN total_price ELSE 0 END) as total_revenue
                        ")
                        ->whereBetween('created_at', [$startDate, $endDate])
                        ->first();

                    return [
                        'total_orders'   => (int) $overview->total_orders,
                        'pending_orders' => (int) $overview->pending_orders,
                        'success_orders' => (int) $overview->success_orders,
                        'failed_orders'  => (int) $overview->failed_orders,
                        'total_revenue'  => (float) $overview->total_revenue,
                    ];
                }
            );

            // ✅ PERBAIKAN: Select specific columns saja
            $recentOrders = Order::query()
                ->select(['id', 'order_id', 'product_name', 'total_price', 'status', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $dailyRevenue = Order::query()
                ->where('status', OrderStatus::SUCCESS->value)
                ->where('created_at', '>=', now()->subDays(7))
                ->select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('SUM(total_price) as revenue'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get();

            // ✅ PERBAIKAN: Cache total products
            $totalProducts = Cache::remember('dashboard:total_products', now()->addMinutes(10), function () {
                return Product::count();
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'overview' => array_merge($stats, [
                        'total_products' => $totalProducts,
                    ]),
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

    public function getBalance(): JsonResponse
    {
        try {
            // ✅ PERBAIKAN: Cache saldo selama 2 menit
            $result = Cache::remember('digiflazz:balance', now()->addMinutes(2), function () {
                return $this->digiflazzService->getBalance();
            });

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal ambil saldo: ' . ($result['message'] ?? 'Unknown error'),
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

    public function productStats(): JsonResponse
    {
        try {
            // ✅ PERBAIKAN: Cache product stats selama 15 menit
            $stats = Cache::remember('dashboard:product_stats', now()->addMinutes(15), function () {
                return Product::query()
                    ->select('category', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active'))
                    ->groupBy('category')
                    ->get();
            });

            return response()->json(['success' => true, 'data' => $stats], 200);

        } catch (\Exception $e) {
            Log::error('DashboardController::productStats gagal', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal mengambil statistik produk.'], 500);
        }
    }
}