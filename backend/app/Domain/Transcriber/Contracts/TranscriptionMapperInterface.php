<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\Transcription;
use DateTimeInterface;

interface TranscriptionMapperInterface
{
    /** @param array{id: string, audio_path: string, file_name: string, name: string, folder_name?: string|null, duration?: float|int|string|null, user_id?: int|null, guest_session_id?: string|null, status?: string, provider_request_id?: string|null, transcript?: string|null, created_at?: DateTimeInterface|string|null, updated_at?: DateTimeInterface|string|null} $data */
    public function fromArray(array $data): Transcription;

    /** @return array{id: string, user_id: int|null, guest_session_id: string|null, audio_path: string, file_name: string, name: string, folder_name?: string|null, duration: float|null, status: string, provider_request_id: string|null, transcript: string|null, created_at: string|null, updated_at: string|null} */
    public function toArray(Transcription $transcription): array;
}
