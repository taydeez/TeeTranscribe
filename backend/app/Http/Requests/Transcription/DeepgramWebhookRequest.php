<?php

namespace App\Http\Requests\Transcription;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use Illuminate\Foundation\Http\FormRequest;

final class DeepgramWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', (string) $this->route('transcription'))) {
            return [];
        }

        return [
            'metadata.request_id' => ['required', 'string', 'max:255'],
            'metadata.duration' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'results.channels.0.alternatives.0.transcript' => ['present', 'nullable', 'string'],
            'results.utterances' => ['sometimes', 'array'],
            'results.utterances.*.start' => ['required', 'numeric', 'min:0'],
            'results.utterances.*.end' => ['required', 'numeric', 'gte:results.utterances.*.start'],
            'results.utterances.*.transcript' => ['required', 'string'],
            'results.utterances.*.speaker' => ['nullable', 'integer', 'min:0'],
            'results.utterances.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }
}
