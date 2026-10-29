<?php

namespace App\Http\Requests\Dubbing;

use App\Domain\Dubbing\Services\SubtitleStyles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDubbingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['client_key' => ['required', 'uuid'],
            'media_type' => ['sometimes', Rule::in(['video', 'audio'])],
            'folder_id' => ['nullable', 'ulid'],
            'video_storage_path' => ['required_unless:media_type,audio', 'prohibited_if:media_type,audio', 'string', 'max:1024'],
            'audio_storage_path' => ['required_if:media_type,audio', 'prohibited_unless:media_type,audio', 'string', 'max:1024'],
            'operation' => ['sometimes', Rule::in(['dubbing', 'subtitles'])],
            'name' => ['nullable', 'string', 'max:255'], 'source_language' => ['nullable', 'string', 'max:20'], 'target_language' => ['required', 'string', 'max:100'],
            'subtitles_enabled' => ['sometimes', 'boolean'], 'subtitle_style' => ['nullable', 'string', Rule::in(SubtitleStyles::NAMES)]];
    }
}
