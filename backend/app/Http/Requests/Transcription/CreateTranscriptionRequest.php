<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Requests\Transcription;

use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTranscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['bail', 'nullable', 'integer', Rule::exists(User::class, 'id')],
            'guest_session_id' => ['bail', 'nullable', 'uuid'],
            'audio_path' => ['required', 'string', 'max:255'],
            'file_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'folder_name' => ['nullable', 'string', 'max:255'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'provider_request_id' => ['bail', 'nullable', 'string', 'max:255', Rule::unique(Transcription::class, 'provider_request_id')],
            'status' => ['sometimes', 'required', 'string', 'max:255'],
            'transcript' => ['nullable', 'string'],
            'id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
