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
        // Tambahan: Lewati cek IP jika fitur dinonaktifkan di config
        $ipCheckEnabled = config('app.admin_ip_check', true);
        $allowedIpsRaw  = config('app.admin_allowed_ips', '');

        if ($ipCheckEnabled === false || $ipCheckEnabled === 'false' || $allowedIpsRaw === 'false') {
            return $next($request);
        }

        $allowedIps = array_filter(
            array_map('trim', explode(',', config('app.admin_allowed_ips', '')))
        );

        // Jika tidak ada IP yang dikonfigurasi: blokir semua di production, izinkan di dev
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

            // Development: izinkan tapi log peringatan
            Log::warning('AdminIpWhitelist: tidak dikonfigurasi — semua IP diizinkan (mode dev).');
            return $next($request);
        }

        $requestIp = $request->ip();

        if (!in_array($requestIp, $allowedIps)) {
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
