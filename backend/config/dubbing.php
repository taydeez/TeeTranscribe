<?php

return [
    'provider' => env('DUBBING_PROVIDER', 'elevenlabs'),
    'audio_provider' => env('DUBBING_AUDIO_PROVIDER', 'elevenlabs'),
    'heygen' => [
        'key' => env('HEYGEN_API_KEY'),
        'mode' => env('HEYGEN_DUBBING_MODE', 'precision'),
        'speaker_num' => filled(env('HEYGEN_DUBBING_SPEAKERS')) ? (int) env('HEYGEN_DUBBING_SPEAKERS') : null,
        'disable_music_track' => env('HEYGEN_DUBBING_DISABLE_MUSIC', false),
        'enable_speech_enhancement' => env('HEYGEN_DUBBING_ENHANCE_SPEECH', false),
        'download_hosts' => array_filter(array_map('trim', explode(',', env('HEYGEN_DOWNLOAD_HOSTS', 'resource.heygen.ai,resource2.heygen.ai,files.heygen.ai')))),
    ],
    'key' => env('ELEVENLABS_API_KEY'),
    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'subtitles' => [
        'font' => env('DUBBING_SUBTITLE_FONT', 'Noto Sans'),
        'fonts_directory' => env('DUBBING_SUBTITLE_FONTS_DIRECTORY'),
        'threads' => (int) env('DUBBING_SUBTITLE_THREADS', 2),
        'max_bytes' => 2 * 1024 * 1024,
    ],
    'max_bytes' => 3 * 1024 * 1024 * 1024,
    'max_duration_ms' => 180 * 60000,
    'download_hosts' => ['storage.googleapis.com'],
];
