<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminIpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = array_filter(
            array_map('trim', explode(',', config('app.admin_allowed_ips', '')))
        );

        if (empty($allowedIps)) {
            if (config('app.env') === 'production') {
                Log::critical('AdminIpWhitelist: ADMIN_ALLOWED_IPS belum diset di production!', [
                    'ip'   => $request->ip(),
                    'path' => $request->path(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak.',
                ], 403);
            }

            Log::warning('AdminIpWhitelist: tidak dikonfigurasi — semua IP diizinkan (mode dev).');
            return $next($request);
        }

        // ✅ PERBAIKAN: Gunakan getClientIp() dengan trusted proxy
        $requestIp = $request->getClientIp();
        
        // Log untuk debugging (hanya di debug mode)
        if (config('app.debug')) {
            Log::debug('IP Headers', [
                'REMOTE_ADDR'          => $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'N/A',
                'getClientIp()'        => $requestIp,
            ]);
        }

        if (!in_array($requestIp, $allowedIps, true)) {
            Log::warning('Akses admin ditolak dari IP tidak dikenal', [
                'ip'         => $requestIp,
                'path'       => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak dari IP ini.',
            ], 403);
        }

        return $next($request);
    }
}