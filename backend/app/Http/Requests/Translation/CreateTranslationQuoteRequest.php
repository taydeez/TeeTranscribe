<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

final class CreateTranslationQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_key' => ['required', 'uuid'], 'text' => ['nullable', 'required_without:transcription_id', 'string', 'max:50000'],
            'transcription_id' => ['nullable', 'ulid'], 'name' => ['nullable', 'string', 'max:255'],
            'folder_id' => ['nullable', 'ulid'],
            'source_language' => ['nullable', 'string', 'max:20'], 'target_language' => ['required', 'string', 'max:20'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'], 'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ];
    }
}
