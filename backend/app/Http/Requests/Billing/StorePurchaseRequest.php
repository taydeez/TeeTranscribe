<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['client_key' => ['required', 'uuid'], 'package_id' => ['required', 'string', 'max:100'], 'currency' => ['required', 'in:NGN,USD'], 'payment_method' => ['required', 'string', 'max:100']];
    }
}
