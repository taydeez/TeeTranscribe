<?php

namespace App\Http\Requests\Admin\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['suspend', 'block', 'restore'])],
            'days' => ['required_if:action,suspend', 'nullable', 'integer', 'min:1', 'max:3650'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/'],
        ];
    }
}
