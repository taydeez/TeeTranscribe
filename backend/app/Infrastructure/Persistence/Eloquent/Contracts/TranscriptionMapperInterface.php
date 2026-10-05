<?php

namespace App\Infrastructure\Persistence\Eloquent\Contracts;

use App\Domain\Transcriber\Entities\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription as TranscriptionModel;

interface TranscriptionMapperInterface
{
    public function toDomain(TranscriptionModel $model): Transcription;

    /** @return array{user_id: int|null, guest_session_id: string|null, audio_path: string, file_name: string, name: string, folder_name?: string|null, duration: float|null, status: string, provider_request_id: string|null, segments: list<array{start: float, end: float, speaker: string|null, text: string, confidence: float|null}>, transcript: string|null} */
    public function toPersistence(Transcription $transcription): array;
}
