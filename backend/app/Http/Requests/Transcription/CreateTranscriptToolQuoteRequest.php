<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

final class CreateTranscriptToolQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation' => ['required', 'in:cleanup,summary'],
            'client_key' => ['required', 'uuid'],
            'draft' => ['sometimes', 'array:transcript,segments'],
            'draft.transcript' => ['required_with:draft', 'string', 'max:50000'],
            'draft.segments' => ['present_with:draft', 'array', 'list', 'max:2500'],
            'draft.segments.*.text' => ['required', 'string', 'max:50000'],
            'draft.segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ];
    }
}
