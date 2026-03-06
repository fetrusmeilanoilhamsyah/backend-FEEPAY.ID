<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MidtransPaymentController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SupportController;

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
|
| Fix BUG-03: gunakan config('feepay.admin_path') yang benar (bukan app.admin_path)
| dan pastikan nilainya tidak null sebelum digunakan.
|--------------------------------------------------------------------------
*/

$adminPath = config('feepay.admin_path');

if (empty($adminPath)) {
    // Di production ini akan fatal — log dan hentikan
    if (config('app.env') === 'production') {
        throw new \RuntimeException(
            'ADMIN_PATH_PREFIX belum diset di .env! Wajib diisi sebelum deploy ke production.'
        );
    }
    // Di development: pakai fallback yang panjang dan tidak mudah ditebak
    $adminPath = 'dev-admin-path-change-me';
}

Route::prefix("admin/{$adminPath}")
    ->middleware(['auth:sanctum', 'verify.pin', 'throttle:60,1'])
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
            // Fix BUG-13: sebelumnya memanggil method 'sync' yang tidak ada.
            // Sekarang memanggil 'sync' yang sudah didefinisikan dengan benar di OrderController.
            Route::post('/{orderId}/sync', [OrderController::class, 'sync']);
        });

        // Manajemen Produk
        Route::prefix('products')->group(function () {
            Route::post('/sync', [ProductController::class, 'sync']);
            Route::post('/bulk-margin', [ProductController::class, 'bulkUpdateMargin']);
            Route::put('/{id}', [ProductController::class, 'update']);
        });
    });
