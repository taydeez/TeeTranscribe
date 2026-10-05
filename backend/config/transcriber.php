<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Fallback Transcription Provider
    |--------------------------------------------------------------------------
    |
    | Nigerian languages configured below use Intron. Other languages use
    | this provider so the public language list can remain provider-neutral.
    |
    */

    'fallback' => env('TRANSCRIBER_FALLBACK', 'deepgram'),

    'deepgram' => [
        'key' => env('DEEPGRAM_API_KEY'),
        'endpoint' => env('DEEPGRAM_API_ENDPOINT', env('DEEPGRAM_API_ENPOINT', 'https://api.deepgram.com/v1/')),
    ],

    'intron' => [
        'key' => env('INTRON_API_KEY'),
        'endpoint' => env('INTRON_API_ENDPOINT', 'https://infer.voice.intron.io'),
        'diarization' => env('INTRON_DIARIZATION', true),
        'diarization_min_duration' => (float) env('INTRON_DIARIZATION_MIN_DURATION', 7200),
        'languages' => array_values(array_filter(array_map('trim', explode(',', env('INTRON_LANGUAGES', 'en-NG,pcm,yo,ig,ha'))))),
        'initial_poll_delay' => (int) env('INTRON_INITIAL_POLL_DELAY', 5),
        'poll_interval' => (int) env('INTRON_POLL_INTERVAL', 15),
        'poll_timeout_minutes' => (int) env('INTRON_POLL_TIMEOUT_MINUTES', 60),
        'download_timeout' => (int) env('INTRON_DOWNLOAD_TIMEOUT', 300),
        'upload_timeout' => (int) env('INTRON_UPLOAD_TIMEOUT', 300),
    ],

];
