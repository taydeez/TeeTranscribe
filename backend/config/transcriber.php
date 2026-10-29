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
    'language_providers' => json_decode(env('TRANSCRIBER_LANGUAGE_PROVIDERS', '{}'), true, 512, JSON_THROW_ON_ERROR),

    'openai' => [
        // This speaker model retires on 2027-02-26; the configurable gpt-transcribe replacement currently returns plain text.
        'model' => env('OPENAI_TRANSCRIPTION_MODEL', 'gpt-4o-transcribe-diarize'),
        'max_duration_ms' => min(5400000, (int) env('OPENAI_TRANSCRIPTION_MAX_MINUTES', 90) * 60000),
        'max_upload_bytes' => 24000000,
        'bitrate' => '32k',
        'conversion_timeout' => 180,
        'request_timeout' => 600,
        'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    ],

    'google' => [
        'model' => env('GOOGLE_SPEECH_MODEL', 'chirp_3'),
        'project' => env('GOOGLE_SPEECH_PROJECT_ID'),
        'location' => env('GOOGLE_SPEECH_LOCATION', 'us'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'bucket' => env('GOOGLE_SPEECH_BUCKET'),
        'diarization' => env('GOOGLE_SPEECH_DIARIZATION', true),
        'word_timestamps' => env('GOOGLE_SPEECH_WORD_TIMESTAMPS', true),
        'poll_interval' => 15,
        'poll_timeout_minutes' => 120,
    ],
    'elevenlabs' => [
        'model' => env('ELEVENLABS_TRANSCRIPTION_MODEL', 'scribe_v2'),
        'key' => env('ELEVENLABS_API_KEY', env('ELEVEN_LABS_API_KEY')),
        'webhook_id' => env('ELEVENLABS_TRANSCRIPTION_WEBHOOK_ID'),
        'webhook_secret' => env('ELEVENLABS_TRANSCRIPTION_WEBHOOK_SECRET'),
        'diarization' => env('ELEVENLABS_TRANSCRIPTION_DIARIZATION', true),
    ],

    'deepgram' => [
        'model' => env('DEEPGRAM_MODEL', 'nova-2'),
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
