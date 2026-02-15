<?php

return [
    'paths' => ['api/*'],
    
    'allowed_methods' => ['*'],
    
    // ✅ WHITELIST specific domains (JANGAN gunakan '*')
    'allowed_origins' => [
        'https://feepay.id',
        'https://www.feepay.id',
        
        // Development only (HAPUS di production!)
        env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
        env('APP_ENV') === 'local' ? 'http://localhost:5173' : null,
        env('APP_ENV') === 'local' ? 'http://127.0.0.1:3000' : null,
    ],
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 0,
    
    'supports_credentials' => true,
];