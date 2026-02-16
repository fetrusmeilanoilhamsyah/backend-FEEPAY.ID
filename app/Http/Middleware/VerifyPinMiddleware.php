<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyPinMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'verify-pin:' . $request->ip();
        
        // Check rate limit (Max 5 attempts per 15 minutes)
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            
            Log::warning('Too many PIN attempts - IP blocked', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'remaining_seconds' => $seconds,
                'user_agent' => $request->userAgent(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
            ], 429);
        }
        
        $pin = $request->header('X-Admin-Pin');
        
        if (!$pin) {
            return response()->json([
                'success' => false,
                'message' => 'Admin PIN is required',
            ], 401);
        }
        
        // Validate PIN format (6 digits)
        if (!preg_match('/^\d{6}$/', $pin)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid PIN format. Must be 6 digits.',
            ], 401);
        }
        
        $correctPin = config('feepay.admin_pin');
        
        if ($pin !== $correctPin) {
            // Hit rate limiter (lockout for 15 minutes = 900 seconds)
            RateLimiter::hit($key, 900);
            
            $attempts = RateLimiter::attempts($key);
            $remaining = 5 - $attempts;
            
            Log::warning('Incorrect admin PIN attempt', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'attempts' => $attempts,
                'remaining' => max(0, $remaining),
            ]);
            
            $message = $remaining > 0 
                ? "Incorrect PIN. {$remaining} attempts remaining."
                : "Incorrect PIN. Account locked.";
            
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }
        
        // Clear rate limit on successful PIN
        RateLimiter::clear($key);
        
        return $next($request);
    }
}