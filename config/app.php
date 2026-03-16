<?php

return [

    'name' => env('APP_NAME', 'Laravel'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Fix PROD-07: Timezone WIB
    |--------------------------------------------------------------------------
    | Sebelumnya 'UTC' — menyebabkan waktu di email dan dashboard
    | selisih 7 jam dari waktu Indonesia Barat.
    */
    'timezone' => 'Asia/Jakarta',

    'locale' => env('APP_LOCALE', 'id'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'id'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'id_ID'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store'  => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Path Prefix
    |--------------------------------------------------------------------------
    | Diambil dari feepay.php juga — ini hanya untuk kompatibilitas
    | middleware AdminIpWhitelist yang baca config('app.admin_allowed_ips').
    */
    'admin_path' => env('ADMIN_PATH_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | Admin Allowed IPs
    |--------------------------------------------------------------------------
    | Fix PROD-02: Wajib diisi di .env sebelum production.
    | Contoh: ADMIN_ALLOWED_IPS=103.28.12.45,180.244.1.1
    | Kosong = blokir semua akses admin di production (lihat AdminIpWhitelist).
    */
    'admin_allowed_ips' => env('ADMIN_ALLOWED_IPS', ''),

    /*
    |--------------------------------------------------------------------------
    | Admin IP Check Toggle
    |--------------------------------------------------------------------------
    | Jika false, pengecekan IP whitelist akan di-skip (tetap aman dengan PIN).
    */
    'admin_ip_check' => env('ADMIN_IP_CHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Admin Email
    |--------------------------------------------------------------------------
    | Fix PROD-03: Email akun admin yang dibuat seeder.
    | Diset di .env: ADMIN_EMAIL=emailkamu@domain.com
    */
    'admin_email' => env('ADMIN_EMAIL', 'admin@feepay.id'),

];
