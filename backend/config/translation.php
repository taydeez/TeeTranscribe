<?php

return [
    'provider' => env('TRANSLATION_PROVIDER', 'google'),
    'google' => ['key' => env('GOOGLE_TRANSLATION_API_KEY')],
    'openai' => ['model' => env('OPENAI_TRANSLATION_MODEL', env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'))],
];
