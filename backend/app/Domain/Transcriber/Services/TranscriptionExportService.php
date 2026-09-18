<?php

namespace App\Domain\Transcriber\Services;

use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Domain\Transcriber\Exceptions\TranscriptionExportNotFoundException;

class TranscriptionExportService
{
    public function __construct(private readonly TranscriptionExportRepositoryInterface $repository) {}

    /** @return list<TranscriptionExport> */
    public function all(): array
    {
        return $this->repository->all();
    }

    /** @return list<TranscriptionExport> */
    public function forTranscription(string $transcriptionId): array
    {
        return $this->repository->forTranscription($transcriptionId);
    }

    public function find(string $id): ?TranscriptionExport
    {
        return $this->repository->find($id);
    }

    public function findOrFail(string $id): TranscriptionExport
    {
        return $this->find($id) ?? throw new TranscriptionExportNotFoundException($id);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): TranscriptionExport
    {
        return $this->repository->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): TranscriptionExport
    {
        return $this->repository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->repository->delete($id);
    }
}
