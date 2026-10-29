<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class CreateUsageQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'],
            'audio_url' => ['required', 'url:http,https', 'max:8192'],
            'audio_storage_path' => ['nullable', 'string', 'max:1024', 'regex:/\Aaudio\/[0-9A-HJKMNP-TV-Z]{26}\.(mp3|wav|m4a|mp4|ogg|oga|flac|webm|aac)\z/i'],
            'language_code' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
        ];
    }
}
