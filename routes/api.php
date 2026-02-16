<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\MidtransPaymentController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\UsdtExchangeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\UsdtRateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SupportController;

// Health check - no rate limit
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'FEEPAY.ID API is running',
        'timestamp' => now()->toIso8601String(),
        'version' => '2.5',
    ]);
});

/*
|--------------------------------------------------------------------------
| RATE LIMITED PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// ✅ Admin Login - STRICT rate limit (5 attempts per minute)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/admin/login', [AuthController::class, 'login']);
});

// ✅ Support - Existing rate limit (5 per minute)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/support/send', [SupportController::class, 'send']);
});

// ✅ Moderate rate limit (20 per minute) - User actions
Route::middleware('throttle:20,1')->group(function () {
    Route::post('/orders/create', [OrderController::class, 'store']);
    Route::post('/payments/submit', [PaymentController::class, 'submit']);
    Route::post('/payments/midtrans/create', [MidtransPaymentController::class, 'createPayment']);
    Route::post('/usdt/submit', [UsdtExchangeController::class, 'submit']);
});

// ✅ Webhooks - Permissive but protected (100 per minute)
Route::middleware('throttle:100,1')->group(function () {
    Route::post('/callback/digiflazz', [CallbackController::class, 'digiflazz']);
    Route::post('/callback/midtrans', [MidtransPaymentController::class, 'handleNotification']);
});

// ✅ Read-only endpoints - Liberal rate limit (60 per minute)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/orders/{orderId}', [OrderController::class, 'show']);
    Route::get('/usdt/rate', [UsdtRateController::class, 'getCurrent']);
    Route::get('/usdt/{trxId}', [UsdtExchangeController::class, 'show']);
    Route::get('/support/contacts', [SupportController::class, 'getContacts']);
});

/*
|--------------------------------------------------------------------------
| ADMIN AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function () {
    // Login sudah di-rate limit di atas
    
    // Protected admin auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| PROTECTED ADMIN ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('admin/' . config('app.admin_path'))
    ->middleware(['admin.ip', 'auth:sanctum', 'verify.pin'])
    ->group(function () {
        
        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [DashboardController::class, 'stats']);
            Route::get('/products', [DashboardController::class, 'productStats']);
        });

        // Orders Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/{id}/confirm', [OrderController::class, 'confirm']);
            Route::post('/{id}/sync', [OrderController::class, 'sync']);
        });

        // Payments Management
        Route::prefix('payments')->group(function () {
            Route::get('/', [PaymentController::class, 'index']);
            Route::post('/{id}/verify', [PaymentController::class, 'verify']);
            Route::get('/{id}/proof', [PaymentController::class, 'downloadProof']);
        });
        
        // USDT Exchange Management
        Route::prefix('usdt')->group(function () {
            Route::get('/', [UsdtExchangeController::class, 'index']);
            Route::post('/{id}/approve', [UsdtExchangeController::class, 'approve']);
            Route::post('/{id}/reject', [UsdtExchangeController::class, 'reject']);
            Route::get('/{id}/proof', [UsdtExchangeController::class, 'downloadProof']);
            Route::post('/rate', [UsdtRateController::class, 'update']);
            Route::get('/rate/history', [UsdtRateController::class, 'history']);
        });

        // Products Management
Route::prefix('products')->group(function () {
    Route::post('/sync', [ProductController::class, 'sync']);
    Route::post('/bulk-margin', [ProductController::class, 'bulkUpdateMargin']); // ← TAMBAH INI
    Route::put('/{id}', [ProductController::class, 'update']);
});
    });