<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreAdminAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'username' => is_string($this->username) ? mb_strtolower(trim($this->username)) : $this->username]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'username' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.-]{2,79}$/', Rule::unique('users', 'username')],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')]];
    }
}
