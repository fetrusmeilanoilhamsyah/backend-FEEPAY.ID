<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyPinMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'verify-pin:' . $request->ip();

        // Rate limit: max 5 percobaan per 15 menit
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            Log::warning('Terlalu banyak percobaan PIN', [
                'ip'                => $request->ip(),
                'path'              => $request->path(),
                'remaining_seconds' => $seconds,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan. Coba lagi dalam ' . ceil($seconds / 60) . ' menit.',
            ], 429);
        }

        $pin = $request->header('X-Admin-Pin');

        if (!$pin) {
            return response()->json([
                'success' => false,
                'message' => 'PIN admin diperlukan.',
            ], 401);
        }

        // Validasi format: tepat 6 digit
        if (!preg_match('/^\d{6}$/', $pin)) {
            return response()->json([
                'success' => false,
                'message' => 'Format PIN tidak valid. Harus 6 digit angka.',
            ], 401);
        }

        $correctPin = config('feepay.admin_pin');

        // hash_equals untuk mencegah timing attack
        if (!$correctPin || !hash_equals((string) $correctPin, (string) $pin)) {
            RateLimiter::hit($key, 900); // lockout 15 menit

            $attempts  = RateLimiter::attempts($key);
            $remaining = max(0, 5 - $attempts);

            Log::warning('PIN admin salah', [
                'ip'        => $request->ip(),
                'path'      => $request->path(),
                'attempts'  => $attempts,
                'remaining' => $remaining,
            ]);

            $message = $remaining > 0
                ? "PIN salah. {$remaining} percobaan tersisa."
                : 'PIN salah. Akun terkunci sementara.';

            return response()->json(['success' => false, 'message' => $message], 403);
        }

        // Berhasil — reset rate limiter
        RateLimiter::clear($key);

        return $next($request);
    }
}
