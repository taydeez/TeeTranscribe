<?php

namespace App\Http\Requests\Translation;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTranslationRequest extends FormRequest
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
