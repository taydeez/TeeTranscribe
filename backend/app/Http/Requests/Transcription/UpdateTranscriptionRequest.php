<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTranscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transcript' => ['required', 'string', 'max:10000000'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'],
            'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ];
    }
}
