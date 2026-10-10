<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'translated_text' => ['required', 'string', 'max:200000'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'], 'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ];
    }
}
