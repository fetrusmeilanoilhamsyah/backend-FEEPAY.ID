<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profit Margin
    |--------------------------------------------------------------------------
    |
    | Margin keuntungan default yang ditambahkan ke harga modal (dalam IDR).
    |
    */

    'margin' => env('FEEPAY_MARGIN', 1000),

    /*
    |--------------------------------------------------------------------------
    | Admin Security Configuration
    |--------------------------------------------------------------------------
    |
    | PIN untuk aksi administratif.
    | Prefix path untuk mengamankan URL Dashboard Admin.
    |
    | ⚠️ WAJIB diset di .env:
    |    FEEPAY_ADMIN_PIN=xxxxxxxx
    |    ADMIN_PATH_PREFIX=xxxxxxxx
    |
    */

    // ✅ FIXED: Hapus fallback 'admin-secret' — wajib diset di .env
    'admin_path' => env('ADMIN_PATH_PREFIX'),

    // ✅ FIXED: Hapus fallback '123456' — wajib diset di .env
    'admin_pin'  => env('FEEPAY_ADMIN_PIN'),

    /*
    |--------------------------------------------------------------------------
    | Support Contact
    |--------------------------------------------------------------------------
    */

    'support_whatsapp' => env('SUPPORT_WHATSAPP', '6281234567890'),
    'support_telegram' => env('SUPPORT_TELEGRAM', 'feepay_support'),
    'support_email'    => env('SUPPORT_EMAIL', 'support@feepay.id'),

];