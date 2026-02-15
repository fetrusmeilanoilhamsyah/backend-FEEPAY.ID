<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        
        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');
        
        // XSS Protection
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        
        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Content Security Policy
        $response->headers->set('Content-Security-Policy', "default-src 'self'");
        
        // HSTS (HTTPS only - production)
        if (config('app.env') === 'production') {
            $response->headers->set(
                'Strict-Transport-Security', 
                'max-age=31536000; includeSubDomains; preload'
            );
        }
        
        return $response;
    }
}