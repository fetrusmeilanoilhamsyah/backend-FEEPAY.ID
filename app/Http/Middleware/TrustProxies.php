<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * ✅ CRITICAL FIX: Trust CloudFlare, nginx reverse proxy, load balancer
     * 
     * @var array<int, string>|string|null
     */
    protected $proxies = '*'; // Trust all proxies (untuk CloudFlare, nginx, dll)
    
    // Atau jika tahu IP proxy spesifik:
    // protected $proxies = [
    //     '127.0.0.1',
    //     '10.0.0.0/8',
    //     '172.16.0.0/12',
    //     '192.168.0.0/16',
    // ];

    /**
     * The headers that should be used to detect proxies.
     *
     * ✅ SECURITY FIX: Tambahkan CloudFlare headers
     * 
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
    
    // Untuk CloudFlare, bisa juga tambahkan custom header:
    // protected $headers = Request::HEADER_X_FORWARDED_ALL;
}