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
    ],

    'allowed_origins_patterns' => [],

    // ✅ FIXED: '*' → header spesifik yang dipakai FEEPAY.ID
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'Accept',
        'X-Requested-With',
        'X-Idempotency-Key',
        'X-Admin-Pin',
    ],

    'exposed_headers' => [],

    'max_age' => 86400, // cache preflight 24 jam

    'supports_credentials' => true,
];