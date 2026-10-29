<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

final class ListTranslationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']];
    }
}
