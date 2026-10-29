<?php

return [
    'paystack' => [
        'enabled' => env('PAYSTACK_ENABLED', true),
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL', rtrim(env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/').'/dashboard/billing'),
        'usd_enabled' => env('PAYSTACK_USD_ENABLED', false),
        'endpoint' => 'https://api.paystack.co',
    ],
    'flutterwave' => [
        'enabled' => env('FLUTTERWAVE_ENABLED', true),
        'secret' => env('FLUTTERWAVE_SECRET_KEY'),
        'callback_url' => env('FLUTTERWAVE_CALLBACK_URL', rtrim(env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/').'/dashboard/billing'),
        'secret_hash' => env('FLUTTERWAVE_WEBHOOK_SECRET_HASH'),
        'usd_enabled' => env('FLUTTERWAVE_USD_ENABLED', false),
        'endpoint' => 'https://api.flutterwave.com/v3',
    ],
];
