<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\UsdtExchangeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\UsdtRateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\SupportController;

/*
|--------------------------------------------------------------------------
| API Routes - FEEPAY.ID
|--------------------------------------------------------------------------
*/

// Health check
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
| PUBLIC ROUTES (Guest Access)
|--------------------------------------------------------------------------
*/

// Support / Customer Service Routes
Route::middleware('throttle:5,1')->post('/support/send', [SupportController::class, 'send']);
Route::get('/support/contacts', [SupportController::class, 'getContacts']);

// Products
Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
});

// Orders
Route::prefix('orders')->group(function () {
    Route::post('/create', [OrderController::class, 'store']);
    Route::get('/{orderId}', [OrderController::class, 'show']);
});

// Payments
Route::prefix('payments')->group(function () {
    Route::post('/submit', [PaymentController::class, 'submit']);
});

// USDT Exchange
Route::prefix('usdt')->group(function () {
    Route::get('/rate', [UsdtRateController::class, 'getCurrent']);
    Route::post('/submit', [UsdtExchangeController::class, 'submit']);
    Route::get('/{trxId}', [UsdtExchangeController::class, 'show']);
});

// Callbacks (Webhooks)
Route::prefix('callback')->group(function () {
    Route::post('/digiflazz', [CallbackController::class, 'digiflazz']);
});

/*
|--------------------------------------------------------------------------
| ADMIN AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    
    // Protected admin auth routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| PROTECTED ADMIN ROUTES (Sanctum + PIN Middleware)
|--------------------------------------------------------------------------
*/

Route::prefix('admin/x7k2m')
    ->middleware(['auth:sanctum', 'verify.pin'])
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
        });

        // USDT Exchange Management
        Route::prefix('usdt')->group(function () {
            Route::get('/', [UsdtExchangeController::class, 'index']);
            Route::post('/{id}/approve', [UsdtExchangeController::class, 'approve']);
            Route::post('/{id}/reject', [UsdtExchangeController::class, 'reject']);
            Route::post('/rate', [UsdtRateController::class, 'update']);
            Route::get('/rate/history', [UsdtRateController::class, 'history']);
        });

        // Products Management
        Route::prefix('products')->group(function () {
            Route::post('/sync', [ProductController::class, 'sync']);
            Route::put('/{id}', [ProductController::class, 'update']);
        });
    });