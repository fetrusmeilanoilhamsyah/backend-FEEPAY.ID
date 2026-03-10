<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

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
        // ✅ SECURITY FIX: Rate limiter per email, bukan cuma IP
        
        // Login rate limiter - 5 attempts per 5 minutes per email
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $key = $email ? 'login:' . $email : 'login:' . $request->ip();
            
            return Limit::perMinutes(5, 5)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again in 5 minutes.'
                    ], 429, $headers);
                });
        });

        // API rate limiter - 60 requests per minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many requests. Please slow down.'
                    ], 429, $headers);
                });
        });

        // Transaction rate limiter - 30 per minute per user
        RateLimiter::for('transactions', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many transactions. Please wait a moment.'
                    ], 429, $headers);
                });
        });

        // Top-up rate limiter - 10 per hour per user
        RateLimiter::for('topup', function (Request $request) {
            return Limit::perHour(10)
                ->by($request->user()?->id)
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many top-up requests. Please try again later.'
                    ], 429, $headers);
                });
        });

        // Webhook rate limiter - 100 per minute (untuk callback dari Digiflazz/Midtrans)
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(100)
                ->by($request->ip());
        });

        // Product sync rate limiter - 1 per 5 minutes
        RateLimiter::for('product-sync', function (Request $request) {
            return Limit::perMinutes(5, 1)
                ->by('product-sync')
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Product sync can only be done once every 5 minutes.'
                    ], 429, $headers);
                });
        });
    }
}