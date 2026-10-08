<?php

return [
    'key' => env('ELEVENLABS_API_KEY'),
    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'max_bytes' => 3 * 1024 * 1024 * 1024,
    'max_duration_ms' => 180 * 60000,
    'download_hosts' => ['storage.googleapis.com'],
];
