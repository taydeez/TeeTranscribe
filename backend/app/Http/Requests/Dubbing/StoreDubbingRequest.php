<?php

namespace App\Http\Requests\Dubbing;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDubbingRequest extends FormRequest
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
