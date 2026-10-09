<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'max:100']];
    }
}
