<?php

return [

    'midtrans' => [
        'server_key'    => env('MIDTRANS_SERVER_KEY'),
        'client_key'    => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'is_sanitized'  => true,
        'is_3ds'        => true,
    ],

    'digiflazz' => [
    'username' => env('DIGIFLAZZ_USERNAME'),
    'api_key'  => env('DIGIFLAZZ_API_KEY'),
    'base_url' => env('DIGIFLAZZ_BASE_URL', 'https://api.digiflazz.com/v1'),
    'allowed_ips' => env('DIGIFLAZZ_ALLOWED_IPS', ''), // ← TAMBAH INI
],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id'   => env('TELEGRAM_CHAT_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'wa_gateway' => [
        'url' => env('WA_GATEWAY_URL'),
    ],

];