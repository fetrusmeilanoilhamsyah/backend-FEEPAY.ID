<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MidtransPaymentController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SupportController;
use App\Models\Order;

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'FEEPAY.ID API is running',
        'timestamp' => now()->toIso8601String(),
        'version'   => '3.0',
    ]);
});

/*
|--------------------------------------------------------------------------
| Public Routes — dengan Rate Limiting
|--------------------------------------------------------------------------
*/

// Admin Login: sangat ketat (5 per menit)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/admin/login', [AuthController::class, 'login']);
});

// Support: 5 per menit
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/support/send', [SupportController::class, 'send']);
    Route::get('/support/contacts', [SupportController::class, 'getContacts']);
});

// Order & Pembayaran: 20 per menit
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/orders/create', [OrderController::class, 'store']);
    Route::post('/payments/midtrans/create', [MidtransPaymentController::class, 'createPayment']);
});

// Webhook provider: permissif (100 per menit) — Digiflazz & Midtrans harus bisa masuk
Route::middleware('throttle:100,1')->group(function () {
    Route::post('/callback/digiflazz', [CallbackController::class, 'digiflazz']);
    Route::post('/midtrans/webhook', [MidtransPaymentController::class, 'handleNotification']);
});

// Read-only publik: 60 per menit
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);

    // POST dipakai karena email pelanggan dikirim sebagai body untuk verifikasi kepemilikan
    Route::post('/orders/{orderId}', [OrderController::class, 'show']);

    // Polling status pembayaran dari checkout.blade.php — cek apakah sudah dibayar
    // Dipakai oleh halaman /payment/checkout/{orderId} setiap 5 detik
    Route::get('/payment/status/{orderId}', function (string $orderId) {
        $order = Order::where('order_id', $orderId)->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order tidak ditemukan.'], 404);
        }

        $status = $order->status->value;

        return response()->json([
            'success' => true,
            'status'  => $status,
            'is_paid' => in_array($status, ['processing', 'success']),
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Auth Routes (token saja, tanpa PIN)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

/*
|--------------------------------------------------------------------------
| Protected Admin Routes (token + PIN + rate limit admin)
|--------------------------------------------------------------------------
*/

$adminPath = config('feepay.admin_path');

if (empty($adminPath)) {
    if (config('app.env') === 'production') {
        throw new \RuntimeException(
            'ADMIN_PATH_PREFIX belum diset di .env! Wajib diisi sebelum deploy ke production.'
        );
    }
    $adminPath = 'dev-admin-path-change-me';
}

Route::prefix("admin/{$adminPath}")
    ->middleware(['auth:sanctum', 'admin.ip', 'verify.pin', 'throttle:60,1'])
    ->group(function () {

        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [DashboardController::class, 'stats']);
            Route::get('/products', [DashboardController::class, 'productStats']);
            Route::get('/balance', [DashboardController::class, 'getBalance']);
        });

        // Manajemen Order
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/{id}/confirm', [OrderController::class, 'confirm']);
            Route::post('/{orderId}/sync', [OrderController::class, 'sync']);
        });

        // Manajemen Produk
        Route::prefix('products')->group(function () {
            Route::post('/sync', [ProductController::class, 'sync']);
            Route::post('/bulk-margin', [ProductController::class, 'bulkUpdateMargin']);
            Route::put('/{id}', [ProductController::class, 'update']);
        });
    });