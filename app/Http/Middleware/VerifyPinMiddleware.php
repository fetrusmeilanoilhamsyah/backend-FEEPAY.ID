<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyPinMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Expects X-Admin-Pin header with 6-digit PIN
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pin = $request->header('X-Admin-Pin');
        $correctPin = config('feepay.admin_pin');

        // Check if PIN is provided
        if (!$pin) {
            Log::warning('Admin PIN not provided', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Admin PIN is required',
            ], 401);
        }

        // Validate PIN format (must be 6 digits)
        if (!preg_match('/^\d{6}$/', $pin)) {
            Log::warning('Invalid PIN format', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid PIN format. Must be 6 digits.',
            ], 401);
        }

        // Verify PIN
        if ($pin !== $correctPin) {
            Log::warning('Incorrect admin PIN attempt', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'attempted_pin' => $pin,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Incorrect PIN',
            ], 403);
        }

        Log::info('Admin PIN verified successfully', [
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return $next($request);
    }
}