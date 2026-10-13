<?php

return [
    'enabled' => env('OPERATIONAL_LOGS_ENABLED', true),
    'retention_days' => (int) env('OPERATIONAL_LOGS_RETENTION_DAYS', 30),
    'provider_hosts' => [
        'api.deepgram.com' => 'deepgram', 'infer.voice.intron.io' => 'intron',
        'speech.googleapis.com' => 'google', 'translation.googleapis.com' => 'google', 'texttospeech.googleapis.com' => 'google',
        'translate.googleapis.com' => 'google', 'api.elevenlabs.io' => 'elevenlabs',
        'api.heygen.com' => 'heygen', 'api.openai.com' => 'openai',
        'api.paystack.co' => 'paystack', 'api.flutterwave.com' => 'flutterwave',
    ],
];
