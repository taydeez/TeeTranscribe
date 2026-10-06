<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

class TranscribeAudioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['quote_id' => ['required', 'ulid'], 'folder_id' => ['nullable', 'ulid']];
    }
}
