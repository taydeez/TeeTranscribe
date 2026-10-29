<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTranscriptToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['quote_id' => ['required', 'ulid']];
    }
}
