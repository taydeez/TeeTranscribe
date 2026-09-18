<?php

namespace App\Http\Requests\Transcription;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranscribeAudioRequest extends FormRequest
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
            'audio_url' => ['required', 'url:http,https', 'max:8192'],
            'guest_session_id' => ['nullable', 'uuid', Rule::exists(GuestSession::class, 'id')],
            'file_name' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'folder_name' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'language_code' => ['required', 'string', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
        ];
    }
}
