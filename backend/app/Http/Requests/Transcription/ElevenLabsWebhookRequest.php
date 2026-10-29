<?php

namespace App\Http\Requests\Transcription;

use Illuminate\Foundation\Http\FormRequest;

final class ElevenLabsWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {

        $secret = config('transcriber.elevenlabs.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503);
        $parts = [];
        foreach (explode(',', $this->header('elevenlabs-signature', '')) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $timestamp = $parts['t'] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 1800, 401);
        $expected = hash_hmac('sha256', $timestamp.'.'.$this->getContent(), $secret);
        abort_unless(hash_equals($expected, $parts['v0'] ?? ''), 401);

        return true;
    }

    public function rules(): array
    {
        if (! in_array($this->input('type'), ['speech_to_text_transcription', 'speech_to_text_transcription_failed'], true)) {
            return [];
        }

        return [
            'data.request_id' => ['required', 'string', 'max:255'],
            'data.webhook_metadata.transcription_id' => ['required', 'string', 'size:26'],
            'data.transcription' => ['sometimes', 'array'],
            'data.transcription.text' => ['sometimes', 'string'],
            'data.transcription.words' => ['sometimes', 'array'],
            'data.transcription.words.*.text' => ['present', 'nullable', 'string'],
            'data.transcription.words.*.type' => ['required', 'string'],
            'data.transcription.words.*.start' => ['nullable', 'numeric', 'min:0'],
            'data.transcription.words.*.end' => ['nullable', 'numeric', 'min:0'],
            'data.transcription.words.*.speaker_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
