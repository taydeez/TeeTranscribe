<?php

namespace App\Http\Requests\Privacy;

use App\Domain\Privacy\Services\RetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePrivacySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['retention' => ['required', 'array:'.implode(',', RetentionPolicy::categories()), 'min:1']];
        foreach (RetentionPolicy::categories() as $category) {
            $rules['retention.'.$category] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.RetentionPolicy::MAX_HOURS];
        }

        return $rules;
    }
}
