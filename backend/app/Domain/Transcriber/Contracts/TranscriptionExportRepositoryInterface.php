<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Domain\Transcriber\Exceptions\TranscriptionExportNotFoundException;
use DateTimeInterface;

interface TranscriptionExportRepositoryInterface
{
    /** @return list<TranscriptionExport> */
    public function all(): array;

    /** @return list<TranscriptionExport> */
    public function forTranscription(string $transcriptionId): array;

    public function find(string $id): ?TranscriptionExport;

    /** @param array{transcription_id: string, format: string, status?: string, storage_path?: string|null, failure_reason?: string|null, processing_started_at?: DateTimeInterface|string|null} $data */
    public function create(array $data): TranscriptionExport;

    /**
     * @param  array{transcription_id?: string, format?: string, status?: string, storage_path?: string|null, failure_reason?: string|null, processing_started_at?: DateTimeInterface|string|null}  $data
     *
     * @throws TranscriptionExportNotFoundException
     */
    public function update(string $id, array $data): TranscriptionExport;

    /** @throws TranscriptionExportNotFoundException */
    public function delete(string $id): bool;
}
