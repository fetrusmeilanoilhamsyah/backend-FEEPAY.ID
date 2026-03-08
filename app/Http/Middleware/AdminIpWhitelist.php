<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminIpWhitelist
{
    /**
     * Handle an incoming request.
     * 
     * ✅ CRITICAL FIX: Gunakan request->ip() setelah TrustProxies middleware
     * Tidak lagi mudah di-bypass dengan X-Forwarded-For header
     */
    public function handle(Request $request, Closure $next)
    {
        // Whitelist IP untuk admin panel
        $allowedIps = config('app.admin_ip_whitelist', []);

        // Jika whitelist kosong, izinkan semua (development mode)
        if (empty($allowedIps)) {
            Log::warning('Admin IP whitelist is empty - allowing all IPs');
            return $next($request);
        }

        // ✅ SECURITY FIX: Gunakan request->ip() yang sudah handle proxy
        $clientIp = $request->ip();

        // Cek apakah IP ada di whitelist
        if (!in_array($clientIp, $allowedIps)) {
            Log::warning('Unauthorized admin access attempt', [
                'ip' => $clientIp,
                'route' => $request->path(),
                'user_agent' => $request->userAgent(),
                'headers' => [
                    'x-forwarded-for' => $request->header('X-Forwarded-For'),
                    'x-real-ip' => $request->header('X-Real-IP'),
                ]
            ]);

            return response()->json([
                'message' => 'Access denied. Your IP is not whitelisted.',
                'ip' => $clientIp
            ], 403);
        }

        Log::info('Admin access granted', [
            'ip' => $clientIp,
            'route' => $request->path()
        ]);

        return $next($request);
    }
}