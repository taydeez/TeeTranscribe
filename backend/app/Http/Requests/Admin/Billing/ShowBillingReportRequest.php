<?php

namespace App\Http\Requests\Admin\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class ShowBillingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['from' => $this->input('from', now()->startOfMonth()->toDateString()),
            'to' => $this->input('to', now()->toDateString())]);
    }

    public function rules(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today']];
    }

    public function period(): array
    {
        return [CarbonImmutable::parse($this->validated('from'))->startOfDay()->toDateTimeString(),
            CarbonImmutable::parse($this->validated('to'))->addDay()->startOfDay()->toDateTimeString()];
    }
}
