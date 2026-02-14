<?php

/**
 * Letakkan file ini di: config/midtrans.php
 *
 * Pastikan variabel berikut ada di .env kamu:
 *   MIDTRANS_SERVER_KEY=SB-Mid-server-xxxxxxxxxxxx
 *   MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxxxxxxxxxx
 *   MIDTRANS_IS_PRODUCTION=false
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Server Key
    |--------------------------------------------------------------------------
    | Digunakan untuk request ke API Midtrans dari backend.
    | JANGAN pernah expose server key ke frontend!
    | Ambil dari: https://dashboard.sandbox.midtrans.com > Settings > Access Keys
    */
    'server_key' => env('MIDTRANS_SERVER_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Client Key
    |--------------------------------------------------------------------------
    | Digunakan di frontend untuk inisialisasi Snap.js
    | Aman untuk ditampilkan di browser.
    */
    'client_key' => env('MIDTRANS_CLIENT_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Mode Production
    |--------------------------------------------------------------------------
    | false = Sandbox (testing, uang tidak benar-benar masuk)
    | true  = Production (LIVE, uang sungguhan)
    |
    | Selalu gunakan false saat development!
    */
    'is_production' => env('MIDTRANS_IS_PRODUCTION', false),

    /*
    |--------------------------------------------------------------------------
    | 3D Secure
    |--------------------------------------------------------------------------
    | Wajib true untuk keamanan transaksi kartu kredit
    */
    '3ds' => true,

];