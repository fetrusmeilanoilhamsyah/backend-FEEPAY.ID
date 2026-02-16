<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminIpWhitelist
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = explode(',', config('app.admin_allowed_ips', ''));
        
        // Remove empty values
        $allowedIps = array_filter(array_map('trim', $allowedIps));
        
        // If no IPs configured, allow all (dev mode)
        if (empty($allowedIps)) {
            Log::warning('Admin IP whitelist not configured - allowing all IPs');
            return $next($request);
        }
        
        $requestIp = $request->ip();
        
        // Check if IP is whitelisted
        if (!in_array($requestIp, $allowedIps)) {
            Log::warning('Unauthorized admin access attempt', [
                'ip' => $requestIp,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toIso8601String(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Access denied from this IP address',
            ], 403);
        }
        
        return $next($request);
    }
}