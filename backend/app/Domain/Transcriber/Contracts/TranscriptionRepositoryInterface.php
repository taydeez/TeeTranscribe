<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Exceptions\TranscriptionNotFoundException;

interface TranscriptionRepositoryInterface
{
    /** @return list<Transcription> */
    public function all(): array;

    public function find(string $id): ?Transcription;

    /** @param array{audio_path: string, file_name: string, name: string, folder_name?: string|null, duration?: float|int|null, user_id?: int|null, guest_session_id?: string|null, provider?: string, status?: string, provider_request_id?: string|null, transcript?: string|null} $data */
    public function create(array $data): Transcription;

    /**
     * @param  array{user_id?: int|null, guest_session_id?: string|null, audio_path?: string, file_name?: string, name?: string, folder_name?: string|null, duration?: float|int|null, status?: string, provider_request_id?: string|null, transcript?: string|null}  $data
     *
     * @throws TranscriptionNotFoundException
     */
    public function update(string $id, array $data): Transcription;

    /** @throws TranscriptionNotFoundException */
    public function updateTranscriptForUser(string $id, int $userId, string $transcript): Transcription;

    /** @throws TranscriptionNotFoundException */
    public function delete(string $id): bool;
}
