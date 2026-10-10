<?php

namespace App\Http\Requests\Admin\Role;

use Illuminate\Foundation\Http\FormRequest;

final class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['required', 'string', 'max:125', 'distinct']];
    }
}
