<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profit Margin
    |--------------------------------------------------------------------------
    |
    | Default profit margin to add to cost price (in IDR)
    |
    */

    'margin' => env('FEEPAY_MARGIN', 1000),

    /*
    |--------------------------------------------------------------------------
    | Admin PIN
    |--------------------------------------------------------------------------
    |
    | 6-digit PIN for admin actions (confirm orders, approve USDT)
    |
    */

    'admin_pin' => env('FEEPAY_ADMIN_PIN', '123456'),

];