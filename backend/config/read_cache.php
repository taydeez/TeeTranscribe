<?php

return [
    'enabled' => env('READ_CACHE_ENABLED', true),
    'store' => env('READ_CACHE_STORE', 'redis'),
    'settings_seconds' => (int) env('READ_CACHE_SETTINGS_SECONDS', 300),
    'languages_seconds' => (int) env('READ_CACHE_LANGUAGES_SECONDS', 86400),
];
