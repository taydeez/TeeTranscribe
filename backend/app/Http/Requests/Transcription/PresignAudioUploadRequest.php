<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresignAudioUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255', 'regex:/\.(mp3|wav|m4a|mp4|ogg|oga|flac|webm|aac)$/i'],
            'content_type' => ['required', 'string', Rule::in([
                'audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/ogg', 'audio/flac', 'audio/webm', 'audio/aac',
            ])],
            'size' => ['required', 'integer', 'min:1', 'max:104857600'],

        ];
    }
}
