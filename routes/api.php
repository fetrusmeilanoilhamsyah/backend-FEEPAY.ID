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

Route::get('/health', function () {
    return response()->json([
        'success'   => true,
        'message'   => 'FEEPAY.ID API is running',
        'timestamp' => now()->toIso8601String(),
        'version'   => '3.0',
    ]);
});

// ✅ PERBAIKAN: Admin Login - sangat ketat
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/admin/login', [AuthController::class, 'login']);
});

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/support/send', [SupportController::class, 'send']);
    Route::get('/support/contacts', [SupportController::class, 'getContacts']);
});

// ✅ PERBAIKAN: Order & Pembayaran dengan rate limit per-email
Route::middleware('throttle:order-creation')->group(function () {
    Route::post('/orders/create', [OrderController::class, 'store']);
    Route::post('/payments/midtrans/create', [MidtransPaymentController::class, 'createPayment']);
});

Route::middleware('throttle:100,1')->group(function () {
    Route::post('/callback/digiflazz', [CallbackController::class, 'digiflazz']);
    Route::post('/midtrans/webhook', [MidtransPaymentController::class, 'handleNotification']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/orders/{orderId}', [OrderController::class, 'show']);

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

Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

$adminPath = config('feepay.admin_path');

if (empty($adminPath)) {
    if (config('app.env') === 'production') {
        throw new \RuntimeException(
            'ADMIN_PATH_PREFIX belum diset di .env! Wajib diisi sebelum deploy ke production.'
        );
    }
    $adminPath = 'dev-admin-path-change-me';
}

// ✅ PERBAIKAN: Gunakan throttle custom untuk admin
Route::prefix("admin/{$adminPath}")
    ->middleware(['auth:sanctum', 'admin.ip', 'verify.pin', 'throttle:admin-ops'])
    ->group(function () {

        Route::prefix('dashboard')->group(function () {
            Route::get('/stats', [DashboardController::class, 'stats']);
            Route::get('/products', [DashboardController::class, 'productStats']);
            Route::get('/balance', [DashboardController::class, 'getBalance']);
        });

        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/{id}/confirm', [OrderController::class, 'confirm']);
            Route::post('/{orderId}/sync', [OrderController::class, 'sync']);
        });

        Route::prefix('products')->group(function () {
            Route::post('/sync', [ProductController::class, 'sync']);
            Route::post('/bulk-margin', [ProductController::class, 'bulkUpdateMargin']);
            Route::put('/{id}', [ProductController::class, 'update']);
        });
    });