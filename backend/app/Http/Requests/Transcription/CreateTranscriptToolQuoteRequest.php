<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

final class CreateTranscriptToolQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['operation' => ['required', 'in:cleanup,summary'], 'client_key' => ['required', 'uuid']];
    }
}
