<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     * 
     * ✅ CRITICAL FIX: Reduced from 7 queries to 1 query
     * ✅ PERFORMANCE: Added caching 5 minutes
     */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $cacheKey = "dashboard:user:{$userId}";

        $stats = Cache::remember($cacheKey, 300, function () use ($userId) {
            // ✅ SINGLE QUERY dengan agregasi
            $orderStats = DB::table('orders')
                ->select([
                    DB::raw('COUNT(*) as total_orders'),
                    DB::raw('COUNT(CASE WHEN status = "success" THEN 1 END) as success_orders'),
                    DB::raw('COUNT(CASE WHEN status = "pending" THEN 1 END) as pending_orders'),
                    DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_orders'),
                    DB::raw('COALESCE(SUM(CASE WHEN status = "success" THEN total_price ELSE 0 END), 0) as total_spent'),
                    DB::raw('COALESCE(SUM(CASE WHEN status = "pending" THEN total_price ELSE 0 END), 0) as pending_amount'),
                ])
                ->where('user_id', $userId)
                ->first();

            // Get user balance
            $user = User::select('balance')->find($userId);

            return [
                'total_orders' => (int) $orderStats->total_orders,
                'success_orders' => (int) $orderStats->success_orders,
                'pending_orders' => (int) $orderStats->pending_orders,
                'failed_orders' => (int) $orderStats->failed_orders,
                'total_spent' => (float) $orderStats->total_spent,
                'pending_amount' => (float) $orderStats->pending_amount,
                'current_balance' => (float) $user->balance,
            ];
        });

        return response()->json(['data' => $stats]);
    }

    /**
     * Get recent orders for dashboard
     * 
     * ✅ FIX: Eager loading untuk prevent N+1
     */
    public function recentOrders(Request $request)
    {
        $userId = auth()->id();
        $limit = $request->input('limit', 10);

        $cacheKey = "dashboard:recent:{$userId}:{$limit}";

        $orders = Cache::remember($cacheKey, 180, function () use ($userId, $limit) {
            return Order::with(['product:id,code,name,category'])
                ->where('user_id', $userId)
                ->select(['id', 'product_id', 'customer_id', 'total_price', 'status', 'trx_id', 'sn', 'created_at'])
                ->latest()
                ->limit($limit)
                ->get();
        });

        return response()->json(['data' => $orders]);
    }

    /**
     * Get transaction chart data
     * Untuk grafik dashboard
     */
    public function transactionChart(Request $request)
    {
        $userId = auth()->id();
        $days = $request->input('days', 7); // Default 7 hari

        $cacheKey = "dashboard:chart:{$userId}:{$days}";

        $chartData = Cache::remember($cacheKey, 600, function () use ($userId, $days) {
            return DB::table('orders')
                ->select([
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(CASE WHEN status = "success" THEN 1 END) as success'),
                    DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed'),
                    DB::raw('COALESCE(SUM(CASE WHEN status = "success" THEN total_price ELSE 0 END), 0) as amount')
                ])
                ->where('user_id', $userId)
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date', 'asc')
                ->get();
        });

        return response()->json(['data' => $chartData]);
    }

    /**
     * Get spending by category
     */
    public function spendingByCategory(Request $request)
    {
        $userId = auth()->id();
        $days = $request->input('days', 30); // Default 30 hari

        $cacheKey = "dashboard:category:{$userId}:{$days}";

        $categoryData = Cache::remember($cacheKey, 600, function () use ($userId, $days) {
            return DB::table('orders')
                ->join('products', 'orders.product_id', '=', 'products.id')
                ->select([
                    'products.category',
                    DB::raw('COUNT(orders.id) as total_orders'),
                    DB::raw('COALESCE(SUM(CASE WHEN orders.status = "success" THEN orders.total_price ELSE 0 END), 0) as total_amount')
                ])
                ->where('orders.user_id', $userId)
                ->where('orders.created_at', '>=', now()->subDays($days))
                ->groupBy('products.category')
                ->orderBy('total_amount', 'desc')
                ->get();
        });

        return response()->json(['data' => $categoryData]);
    }

    /**
     * Admin dashboard statistics
     * Untuk admin panel
     */
    public function adminStats(Request $request)
    {
        // Pastikan user adalah admin
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $cacheKey = 'dashboard:admin:stats';

        $stats = Cache::remember($cacheKey, 300, function () {
            return [
                'total_users' => User::count(),
                'active_users' => User::where('created_at', '>=', now()->subDays(30))->count(),
                'total_products' => Product::count(),
                'active_products' => Product::where('status', 'active')->count(),
                'total_orders' => Order::count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
                'success_orders' => Order::where('status', 'success')->count(),
                'failed_orders' => Order::where('status', 'failed')->count(),
                'total_revenue' => Order::where('status', 'success')->sum('total_price'),
                'today_revenue' => Order::where('status', 'success')
                    ->whereDate('created_at', today())
                    ->sum('total_price'),
                'total_balance' => User::sum('balance'),
            ];
        });

        return response()->json(['data' => $stats]);
    }

    /**
     * Admin transaction chart
     */
    public function adminTransactionChart(Request $request)
    {
        if (!auth()->user()->is_admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $days = $request->input('days', 30);
        $cacheKey = "dashboard:admin:chart:{$days}";

        $chartData = Cache::remember($cacheKey, 300, function () use ($days) {
            return DB::table('orders')
                ->select([
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as total'),
                    DB::raw('COUNT(CASE WHEN status = "success" THEN 1 END) as success'),
                    DB::raw('COUNT(CASE WHEN status = "failed" THEN 1 END) as failed'),
                    DB::raw('COUNT(CASE WHEN status = "pending" THEN 1 END) as pending'),
                    DB::raw('COALESCE(SUM(CASE WHEN status = "success" THEN total_price ELSE 0 END), 0) as revenue')
                ])
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date', 'asc')
                ->get();
        });

        return response()->json(['data' => $chartData]);
    }

    /**
     * Clear user dashboard cache
     * Dipanggil setelah create/update order
     */
    public static function clearUserCache(int $userId): void
    {
        Cache::forget("dashboard:user:{$userId}");
        Cache::forget("dashboard:recent:{$userId}:10");
        Cache::forget("dashboard:chart:{$userId}:7");
        Cache::forget("dashboard:category:{$userId}:30");
    }

    /**
     * Clear admin dashboard cache
     */
    public static function clearAdminCache(): void
    {
        Cache::forget('dashboard:admin:stats');
        Cache::forget('dashboard:admin:chart:30');
    }
}