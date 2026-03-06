<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profit Margin Default
    |--------------------------------------------------------------------------
    | Margin keuntungan default yang ditambahkan ke harga modal (dalam IDR).
    | Digunakan saat sync produk baru dari Digiflazz.
    */
    'margin' => env('FEEPAY_MARGIN', 2000),

    /*
    |--------------------------------------------------------------------------
    | Admin Security
    |--------------------------------------------------------------------------
    | WAJIB diset di .env sebelum deploy ke production:
    |   ADMIN_PATH_PREFIX=namapath_panjang_yang_tidak_mudah_ditebak
    |   FEEPAY_ADMIN_PIN=123456
    |
    | Jika tidak diset, aplikasi akan throw RuntimeException di production.
    */
    'admin_path' => env('ADMIN_PATH_PREFIX'),    // Tidak ada fallback — wajib diset
    'admin_pin'  => env('FEEPAY_ADMIN_PIN'),     // Tidak ada fallback — wajib diset

    /*
    |--------------------------------------------------------------------------
    | Support Contact
    |--------------------------------------------------------------------------
    */
    'support_whatsapp' => env('SUPPORT_WHATSAPP', '6281234567890'),
    'support_telegram' => env('SUPPORT_TELEGRAM', 'feepay_support'),
    'support_email'    => env('SUPPORT_EMAIL', 'support@feepay.id'),

];
