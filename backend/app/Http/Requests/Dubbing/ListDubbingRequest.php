<?php

namespace App\Http\Requests\Dubbing;

use Illuminate\Foundation\Http\FormRequest;

final class ListDubbingRequest extends FormRequest
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
