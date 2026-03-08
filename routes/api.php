<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TopupController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ✅ Public routes dengan rate limiting ketat
Route::middleware(['throttle:login'])->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
});

// ✅ Webhook routes - TANPA auth, tapi DENGAN signature validation
Route::prefix('webhooks')->middleware(['throttle:webhook'])->group(function () {
    Route::post('/digiflazz', [CallbackController::class, 'digiflazz']);
    Route::post('/midtrans', [CallbackController::class, 'midtrans']);
});

// ✅ Protected routes - require authentication
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    
    // Auth routes
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // Dashboard routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/recent-orders', [DashboardController::class, 'recentOrders']);
        Route::get('/chart', [DashboardController::class, 'transactionChart']);
        Route::get('/spending', [DashboardController::class, 'spendingByCategory']);
    });

    // Product routes - read operations dengan rate limit lebih tinggi
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/category/{category}', [ProductController::class, 'byCategory']);
        Route::get('/{id}', [ProductController::class, 'show']);
    });

    // Order routes - write operations dengan rate limit ketat
    Route::prefix('orders')->middleware(['throttle:transactions'])->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']);
        Route::get('/{id}', [OrderController::class, 'show']);
        Route::post('/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('/{id}/retry', [OrderController::class, 'retry']);
    });

    // Top-up routes dengan rate limit sangat ketat
    Route::prefix('topup')->middleware(['throttle:topup'])->group(function () {
        Route::post('/', [TopupController::class, 'create']);
        Route::get('/history', [TopupController::class, 'history']);
        Route::get('/{id}', [TopupController::class, 'show']);
        Route::post('/{id}/check-status', [TopupController::class, 'checkStatus']);
    });

    // ✅ Admin routes - require admin role + IP whitelist
    Route::prefix('admin')->middleware(['admin', 'admin.ip'])->group(function () {
        
        // Dashboard admin
        Route::get('/dashboard', [DashboardController::class, 'adminStats']);
        Route::get('/dashboard/chart', [DashboardController::class, 'adminTransactionChart']);

        // Product management
        Route::post('/products/sync', [ProductController::class, 'sync'])
            ->middleware(['throttle:product-sync']);
        Route::put('/products/{id}/status', [ProductController::class, 'updateStatus']);
        Route::post('/products/bulk-status', [ProductController::class, 'bulkUpdateStatus']);

        // Order management
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
        
        // User management
        Route::get('/users', [AuthController::class, 'listUsers']);
        Route::get('/users/{id}', [AuthController::class, 'showUser']);
        Route::put('/users/{id}/balance', [AuthController::class, 'updateBalance']);
        Route::put('/users/{id}/status', [AuthController::class, 'updateUserStatus']);
    });
});

// Health check endpoint (untuk monitoring)
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'service' => 'PPOB API'
    ]);
});