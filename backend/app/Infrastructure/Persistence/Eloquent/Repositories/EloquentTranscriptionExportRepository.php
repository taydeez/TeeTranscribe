<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Domain\Transcriber\Exceptions\TranscriptionExportNotFoundException;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport as ExportModel;
use RuntimeException;

class EloquentTranscriptionExportRepository implements TranscriptionExportRepositoryInterface
{
    /** @return list<TranscriptionExport> */
    public function all(): array
    {
        return ExportModel::query()->orderByDesc('id')->get()
            ->map(fn (ExportModel $model): TranscriptionExport => $this->toDomain($model))->all();
    }

    /** @return list<TranscriptionExport> */
    public function forTranscription(string $transcriptionId): array
    {
        return ExportModel::query()->where('transcription_id', $transcriptionId)->orderByDesc('id')->get()
            ->map(fn (ExportModel $model): TranscriptionExport => $this->toDomain($model))->all();
    }

    public function find(string $id): ?TranscriptionExport
    {
        $model = ExportModel::query()->find($id);

        return $model === null ? null : $this->toDomain($model);
    }

    public function create(array $data): TranscriptionExport
    {
        $model = new ExportModel($data);

        if (! $model->save()) {
            throw new RuntimeException('The transcription export could not be created.');
        }

        return $this->toDomain($model->refresh());
    }

    public function update(string $id, array $data): TranscriptionExport
    {
        $model = $this->findModelOrFail($id);

        if (! $model->update($data)) {
            throw new RuntimeException('The transcription export could not be updated.');
        }

        return $this->toDomain($model->refresh());
    }

    public function delete(string $id): bool
    {
        return (bool) $this->findModelOrFail($id)->delete();
    }

    private function findModelOrFail(string $id): ExportModel
    {
        return ExportModel::query()->find($id) ?? throw new TranscriptionExportNotFoundException($id);
    }

    private function toDomain(ExportModel $model): TranscriptionExport
    {
        return new TranscriptionExport(
            id: $model->id,
            transcriptionId: $model->transcription_id,
            format: $model->format,
            status: $model->status,
            storagePath: $model->storage_path,
            failureReason: $model->failure_reason,
            processingStartedAt: $model->processing_started_at?->toDateTimeImmutable(),
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
