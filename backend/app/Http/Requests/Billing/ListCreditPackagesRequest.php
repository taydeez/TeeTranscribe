<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class ListCreditPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['currency' => ['sometimes', 'in:NGN,USD']];
    }
}
