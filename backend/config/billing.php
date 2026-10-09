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
            'openai' => [env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-transcribe-diarize') => [
                'unit' => 'minute',
                'credits' => env('BILLING_OPENAI_TRANSCRIPTION_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_OPENAI_TRANSCRIPTION_USD_PER_MINUTE'),
                'provider_currency' => 'USD',
            ]],
            'google' => [env('GOOGLE_SPEECH_MODEL', 'chirp_3') => [
                'unit' => 'minute',
                'credits' => env('BILLING_GOOGLE_TRANSCRIPTION_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_GOOGLE_TRANSCRIPTION_USD_PER_MINUTE'),
                'provider_currency' => 'USD',
            ]],
            'elevenlabs' => [env('ELEVENLABS_TRANSCRIPTION_MODEL', 'scribe_v2') => [
                'unit' => 'minute',
                'credits' => env('BILLING_ELEVENLABS_TRANSCRIPTION_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_ELEVENLABS_TRANSCRIPTION_USD_PER_MINUTE'),
                'provider_currency' => 'USD',
            ]],
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
        'subtitles' => ['deepgram' => ['nova-2' => [
            'unit' => 'minute', 'credits' => env('BILLING_SUBTITLES_CREDITS_PER_MINUTE'),
            'provider_cost' => env('BILLING_SUBTITLES_USD_PER_MINUTE'), 'provider_currency' => 'USD',
        ]]],
        'translation' => ['openai' => [env('OPENAI_TRANSLATION_MODEL', env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini')) => [
            'unit' => '1000_characters',
            'credits' => env('BILLING_OPENAI_TRANSLATION_CREDITS_PER_1000_CHARACTERS'),
            'provider_cost' => env('BILLING_OPENAI_TRANSLATION_USD_PER_1000_CHARACTERS'),
            'provider_currency' => 'USD',
        ]], 'google' => ['nmt' => [
            'unit' => '1000_characters',
            'credits' => env('BILLING_GOOGLE_TRANSLATION_CREDITS_PER_1000_CHARACTERS'),
            'provider_cost' => env('BILLING_GOOGLE_TRANSLATION_USD_PER_1000_CHARACTERS'),
            'provider_currency' => 'USD',
        ]]],
        'cleanup' => ['openai' => [env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini') => [
            'unit' => '1000_characters',
            'credits' => env('BILLING_OPENAI_CLEANUP_CREDITS_PER_1000_CHARACTERS'),
            'provider_cost' => env('BILLING_OPENAI_CLEANUP_USD_PER_1000_CHARACTERS'),
            'provider_currency' => 'USD',
        ]]],
        'summary' => ['openai' => [env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini') => [
            'unit' => '1000_characters',
            'credits' => env('BILLING_OPENAI_SUMMARY_CREDITS_PER_1000_CHARACTERS'),
            'provider_cost' => env('BILLING_OPENAI_SUMMARY_USD_PER_1000_CHARACTERS'),
            'provider_currency' => 'USD',
        ]]],
        'dubbing' => ['heygen' => [
            'precision' => ['unit' => 'minute', 'credits' => env('BILLING_HEYGEN_PRECISION_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_HEYGEN_PRECISION_USD_PER_MINUTE'), 'provider_currency' => 'USD'],
            'speed' => ['unit' => 'minute', 'credits' => env('BILLING_HEYGEN_SPEED_CREDITS_PER_MINUTE'),
                'provider_cost' => env('BILLING_HEYGEN_SPEED_USD_PER_MINUTE'), 'provider_currency' => 'USD'],
        ], 'elevenlabs' => ['dubbing_v2' => [
            'unit' => 'minute',
            'credits' => env('BILLING_ELEVENLABS_DUBBING_CREDITS_PER_MINUTE'),
            'provider_cost' => env('BILLING_ELEVENLABS_DUBBING_USD_PER_MINUTE'),
            'provider_currency' => 'USD',
        ]]],
    ],
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),
    'media_max_bytes' => 5 * 1024 * 1024 * 1024,
];
