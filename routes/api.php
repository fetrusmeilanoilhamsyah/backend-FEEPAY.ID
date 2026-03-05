<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\MidtransPaymentController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SupportController;

// Health check - no rate limit
Route::get('/health', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'FEEPAY.ID API is running',
        'timestamp' => now()->toIso8601String(),
        'version'   => '2.5',
    ]);
});

/*
|--------------------------------------------------------------------------
| RATE LIMITED PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// Admin Login - STRICT rate limit (5 attempts per minute)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/admin/login', [AuthController::class, 'login']);
});

// Support - rate limit (5 per minute)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/support/send', [SupportController::class, 'send']);
});

// User actions - rate limit (20 per minute)
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/orders/create', [OrderController::class, 'store']);
    Route::post('/payments/midtrans/create', [MidtransPaymentController::class, 'createPayment']);
});

// Webhooks - permissive (100 per minute)
Route::middleware('throttle:100,1')->group(function () {
    Route::post('/callback/digiflazz', [CallbackController::class, 'digiflazz']);
    Route::post('/midtrans/webhook', [MidtransPaymentController::class, 'handleNotification']);
});

// Read-only endpoints (60 per minute)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/orders/{orderId}', [OrderController::class, 'show']);
    Route::get('/support/contacts', [SupportController::class, 'getContacts']);
});

/*
|--------------------------------------------------------------------------
| ADMIN AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']); // ✅ ADDED: fix 404 di frontend
    });
});

/*
|--------------------------------------------------------------------------
| PROTECTED ADMIN ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('admin/' . config('app.admin_path'))
    -->middleware(['auth:sanctum', 'verify.pin'])  // Hapus 'admin.ip'
    ->group(function () {

        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [DashboardController::class, 'stats']);
            Route::get('/products', [DashboardController::class, 'productStats']);
            Route::get('/balance', [DashboardController::class, 'getBalance']);
        });

        // Orders Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/{id}/confirm', [OrderController::class, 'confirm']);
            Route::post('/{id}/sync', [OrderController::class, 'sync']);
        });

        // ✅ REMOVED: Payments Management (zombie - manual payment dihapus)

        // Products Management
        Route::prefix('products')->group(function () {
            Route::post('/sync', [ProductController::class, 'sync']);
            Route::post('/bulk-margin', [ProductController::class, 'bulkUpdateMargin']);
            Route::put('/{id}', [ProductController::class, 'update']);
        });
    });