<?php

return [
    'key' => env('OPENAI_API_KEY'),
    'endpoint' => env('OPENAI_API_URL', 'https://api.openai.com/v1/'),
    'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1-mini'),
    'timeout' => 180,
    'max_output_tokens' => 16000,
];
