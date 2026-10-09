<?php

use App\Domain\Transcriber\Services\OpenAITranscriptionCapabilities;

test('speaker labels and timestamps are enabled only for models that actually return them', function () {
    expect(OpenAITranscriptionCapabilities::speakers('gpt-4o-transcribe-diarize'))->toBeTrue()
        ->and(OpenAITranscriptionCapabilities::timestamps('gpt-4o-transcribe-diarize'))->toBeTrue()
        ->and(OpenAITranscriptionCapabilities::speakers('whisper-1'))->toBeFalse()
        ->and(OpenAITranscriptionCapabilities::timestamps('whisper-1'))->toBeTrue()
        ->and(OpenAITranscriptionCapabilities::timestamps('gpt-transcribe'))->toBeFalse()
        ->and(OpenAITranscriptionCapabilities::timestamps('gpt-4o-mini-transcribe'))->toBeFalse();
});

test('unknown transcription models fail before any billable provider request', function () {
    expect(fn () => OpenAITranscriptionCapabilities::format('unpriced-future-model'))->toThrow(InvalidArgumentException::class);
});
