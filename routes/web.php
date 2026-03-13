<?php

use Illuminate\Support\Facades\Route;

// Redirect unauthenticated ke JSON — mencegah Laravel redirect ke /login HTML
Route::get('/login', function () {
    return response()->json([
        'success' => false,
        'message' => 'Unauthenticated. Please login via /api/admin/login',
    ], 401);
})->name('login');

// Halaman checkout Midtrans Snap
// Dipanggil oleh frontend setelah dapat snap_token dari /api/payments/midtrans/create
Route::get('/payment/checkout/{orderId}', function (string $orderId) {
    $order = \App\Models\Order::where('order_id', $orderId)->first();

    if (!$order || !$order->midtrans_snap_token) {
        abort(404, 'Order tidak ditemukan atau belum memiliki token pembayaran.');
    }

    $isProduction = config('services.midtrans.is_production');
    $snapJsUrl    = $isProduction
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';

    return view('payment.checkout', [
        'snapToken'   => $order->midtrans_snap_token,
        'snapJsUrl'   => $snapJsUrl,
        'clientKey'   => config('services.midtrans.client_key'),
        'orderId'     => $order->order_id,
        'productName' => $order->product_name,
        'amount'      => $order->total_price,
    ]);
});

// Redirect dari Midtrans setelah pembayaran selesai (callback finish)
// Midtrans akan redirect ke sini setelah user klik "Kembali ke Merchant"
Route::get('/payment/finish', function () {
    $orderId = request('order_id');
    $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://feepay.web.id'));

    // Redirect ke frontend dengan order_id
    return redirect("{$frontendUrl}/riwayat?order_id={$orderId}&status=success");
});

// Halaman sukses (redirect dari checkout.blade.php setelah onSuccess)
Route::get('/payment/success', function () {
    $orderId     = request('order_id');
    $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'https://feepay.web.id'));

    return redirect("{$frontendUrl}/riwayat?order_id={$orderId}&status=success");
});