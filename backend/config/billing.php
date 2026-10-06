<?php

return [
    'free_credits' => env('BILLING_FREE_CREDITS', '0'),
    'quote_minutes' => 45,
    'checkout_minutes' => 30,
    // Deepgram callbacks expire after 24 hours; recover stranded reservations afterwards.
    'processing_timeout_hours' => 26,
    'fx' => [
        'ngn_per_usd' => env('BILLING_NGN_PER_USD'),
        'updated_at' => env('BILLING_FX_UPDATED_AT'),
        'max_age_hours' => 24,
    ],
    'packages' => [
        'starter' => ['name' => 'Starter', 'credits' => 5000],
        'regular' => ['name' => 'Regular', 'credits' => 15000],
        'large' => ['name' => 'Large', 'credits' => 50000],
    ],
    'rates' => [
        'transcription' => [
            'deepgram' => [
                env('DEEPGRAM_MODEL', 'nova-2') => [
                    'unit' => 'minute',
                    'credits' => env('BILLING_DEEPGRAM_CREDITS_PER_MINUTE'),
                    'provider_cost' => env('BILLING_DEEPGRAM_USD_PER_MINUTE'),
                    'provider_currency' => 'USD',
                ],
            ],
            'intron' => ['default' => [
                'unit' => 'minute',
                'credits' => env('BILLING_INTRON_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_INTRON_USD_PER_MINUTE'),
                'provider_currency' => 'USD',
            ]],
        ],
        'translation' => [],
        'dubbing' => [],
    ],
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
    'media_max_bytes' => 5 * 1024 * 1024 * 1024,
];
