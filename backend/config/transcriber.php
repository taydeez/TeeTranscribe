<?php
/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('TRANSCRIBER', 'deepgram'),

    'deepgram' => [
        'key' => env('DEEPGRAM_API_KEY'),
        'endpoint' => env('DEEPGRAM_API_ENPOINT','https://api.deepgram.com/v1/')
    ]



    ];
