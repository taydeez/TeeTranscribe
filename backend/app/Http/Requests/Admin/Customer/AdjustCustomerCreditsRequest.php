<?php

namespace App\Http\Requests\Admin\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdjustCustomerCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['add', 'remove'])],
            'credit_units' => ['required', 'integer', 'min:1', 'max:100000000'],
            'client_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/'],
        ];
    }
}
