<?php

namespace App\Http\Requests\Privacy;

use App\Domain\Privacy\Services\RetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class StorePrivacyDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_type' => ['required', 'in:upload,quote,transcription,translation,dubbing,folder'],
            'resource_id' => ['required', 'ulid'], 'scope' => ['required', 'in:project,source,generated'],
            'category' => ['sometimes', 'nullable', 'in:'.implode(',', RetentionPolicy::categories())],
        ];
    }
}
