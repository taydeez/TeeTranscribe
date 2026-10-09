<?php

namespace App\Domain\Transcriber\Services;

use InvalidArgumentException;

final class OpenAITranscriptionCapabilities
{
    public static function format(string $model): string
    {
        return match ($model) {
            'gpt-4o-transcribe-diarize' => 'diarized_json',
            'whisper-1' => 'verbose_json',
            'gpt-transcribe', 'gpt-4o-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-mini-transcribe-2025-12-15' => 'json',
            default => throw new InvalidArgumentException('Unsupported transcription model.'),
        };
    }

    public static function speakers(string $model): bool
    {
        return self::format($model) === 'diarized_json';
    }

    public static function timestamps(string $model): bool
    {
        return self::format($model) !== 'json';
    }
}
