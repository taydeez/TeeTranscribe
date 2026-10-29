<?php

namespace App\Http\Requests\Admin\Log;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListOperationalLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', Rule::in(['application', 'exception', 'job', 'provider', 'lifecycle', 'media'])],
            'level' => ['nullable', Rule::in(['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'debug'])],
            'provider' => ['nullable', 'string', 'max:40'],
            'operation_type' => ['nullable', 'string', 'max:40'],
            'operation_id' => ['nullable', 'string', 'max:100'],
            'provider_request_id' => ['nullable', 'string', 'max:255'],
            'job_id' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'sort' => ['sometimes', Rule::in(['newest', 'oldest'])],
        ];
    }
}
