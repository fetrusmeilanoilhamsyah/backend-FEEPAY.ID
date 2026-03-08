<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ✅ PERBAIKAN: Rate limit per-email untuk order creation
        RateLimiter::for('order-creation', function (Request $request) {
            $email = $request->input('customer_email', $request->ip());
            
            return Limit::perMinutes(5, 10)->by($email)->response(function (Request $request, array $headers) {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak pesanan dalam waktu singkat. Silakan tunggu 5 menit.',
                ], 429, $headers);
            });
        });

        // ✅ PERBAIKAN: Rate limit untuk admin operations
        RateLimiter::for('admin-ops', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });
    }
}