<?php

namespace App\Http\Requests\Upload;

use App\Http\Requests\Transcription\PresignAudioUploadRequest;
use Illuminate\Validation\Rule;

class StartMultipartUploadRequest extends PresignAudioUploadRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'content_type' => ['required', 'string', Rule::in([
                'audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/ogg', 'audio/flac', 'audio/webm', 'audio/aac',
                'video/mp4', 'video/webm',
            ])],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('uploads.max_bytes')],
            'client_key' => ['required', 'uuid'],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'source_kind' => ['sometimes', 'in:audio,video,recording'],
        ]);
    }
}
