<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Trusted proxies untuk production
     * Sesuaikan dengan setup VPS/Load Balancer kamu
     */
    protected $proxies = [
        '127.0.0.1',
        '::1',
        // Tambahkan IP internal load balancer atau proxy jika ada
        // Contoh: '10.0.0.1', '172.16.0.1'
    ];

    /**
     * Headers yang harus dipercaya dari proxy
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | 
                         Request::HEADER_X_FORWARDED_HOST | 
                         Request::HEADER_X_FORWARDED_PORT | 
                         Request::HEADER_X_FORWARDED_PROTO;
}