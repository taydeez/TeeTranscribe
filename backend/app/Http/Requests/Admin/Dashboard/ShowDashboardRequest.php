<?php

namespace App\Http\Requests\Admin\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['from' => $this->input('from', now()->subDays(29)->toDateString()), 'to' => $this->input('to', now()->toDateString()),
            'environment' => $this->input('environment', 'live')]);
    }

    public function rules(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'environment' => ['required', Rule::in(['live', 'test', 'all'])]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('from') && ! $validator->errors()->has('to')
                && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 365) {
                $validator->errors()->add('to', 'Choose a period of up to 366 days.');
            }
        }];
    }

    public function period(): array
    {
        return [CarbonImmutable::parse($this->validated('from'), 'UTC')->startOfDay()->toDateTimeString(),
            CarbonImmutable::parse($this->validated('to'), 'UTC')->addDay()->startOfDay()->toDateTimeString(), $this->validated('environment')];
    }
}
