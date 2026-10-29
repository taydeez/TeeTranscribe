<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangeInitialPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['current_password' => ['required', 'string'], 'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()]];
    }
}
