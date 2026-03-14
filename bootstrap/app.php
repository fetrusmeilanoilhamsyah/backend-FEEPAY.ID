<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:      __DIR__ . '/../routes/web.php',
        api:      __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health:   '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ─── Trust Proxies (Cloudflare/Load Balancer) untuk Security & IP Whitelist
        $middleware->trustProxies(at: '*');

        // ─── Fix PROD-04: Middleware global yang sebelumnya di Kernel.php ────
        // Laravel 12 tidak pakai Kernel.php lagi — harus didaftarkan di sini.
        // Sebelumnya SecurityHeaders dan ForceHttps tidak aktif sama sekali.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\ForceHttps::class);

        // ─── Alias middleware untuk route ─────────────────────────────────────
        $middleware->alias([
            'verify.pin' => \App\Http\Middleware\VerifyPinMiddleware::class,
            'admin.ip'   => \App\Http\Middleware\AdminIpWhitelist::class,
        ]);

        // ─── Nonaktifkan CSRF untuk semua route API ───────────────────────────
        $middleware->validateCsrfTokens(except: ['api/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
