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
    | PIN 6 digit untuk aksi administratif seperti konfirmasi pesanan manual.
    | Prefix path digunakan untuk mengamankan URL Dashboard Admin.
    |
    */

    'admin_path' => env('ADMIN_PATH_PREFIX', 'admin-secret'),

    'admin_pin' => env('FEEPAY_ADMIN_PIN', '123456'),

];