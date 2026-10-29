<?php

namespace App\Http\Requests\Upload;

use Illuminate\Foundation\Http\FormRequest;

final class SignMultipartUploadPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['part_number' => ['required', 'integer', 'min:1']];
    }
}
