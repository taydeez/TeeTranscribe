<?php

namespace App\Http\Requests\Folder;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
