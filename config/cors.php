<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://feepay.web.id',
        'https://www.feepay.web.id',
        'https://api.feepay.web.id',
        'https://feepay.id',
        'https://www.feepay.id',

        // Domain Vercel — ganti sesuai nama project kamu
        'https://NAMA_PROJECT_KAMU.vercel.app',

        // Kalau sudah pakai custom domain di Vercel, tambah di sini juga:
        // 'https://custom-domain-kamu.com',
    ],

    'allowed_origins_patterns' => [],

    // Hanya header yang benar-benar dipakai — tidak pakai wildcard '*'
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'Accept',
        'X-Requested-With',
        'X-Idempotency-Key',
        'X-Admin-Pin',
    ],

    'exposed_headers' => [],

    'max_age' => 86400, // Preflight di-cache 24 jam

    'supports_credentials' => true,
];