<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class ListBillingHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(in_array($this->route('type'), ['payments', 'usage', 'ledger'], true), 404);

        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
