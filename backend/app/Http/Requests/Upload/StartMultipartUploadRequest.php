<?php

namespace App\Http\Requests\Upload;

use App\Http\Requests\Transcription\PresignAudioUploadRequest;

class StartMultipartUploadRequest extends PresignAudioUploadRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'size' => ['required', 'integer', 'min:1', 'max:'.config('uploads.max_bytes')],
            'client_key' => ['required', 'uuid'],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
    }
}
