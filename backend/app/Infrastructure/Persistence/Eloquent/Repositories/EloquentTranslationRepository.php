<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Translation\Entities\Translation;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation as TranslationModel;

final class EloquentTranslationRepository implements TranslationRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?Translation
    {
        $record = TranslationModel::query()->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function create(array $data): Translation
    {
        return $this->toDomain(TranslationModel::create($data)->refresh());
    }

    public function update(string $id, array $data): Translation
    {
        $record = TranslationModel::findOrFail($id);
        $record->update($data);

        return $this->toDomain($record->refresh());
    }

    public function paginateForUser(int $userId, int $page, int $perPage): array
    {
        $result = TranslationModel::where('user_id', $userId)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return ['data' => $result->getCollection()->map(fn ($record) => $this->toDomain($record))->all(), 'meta' => [
            'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'per_page' => $result->perPage(), 'total' => $result->total(),
        ]];
    }

    public function sourceForUser(string $transcriptionId, int $userId): ?array
    {
        $record = Transcription::where('user_id', $userId)->find($transcriptionId);

        return $record === null ? null : ['name' => $record->name, 'text' => $record->transcript, 'segments' => $record->segments ?? []];
    }

    private function toDomain(TranslationModel $record): Translation
    {
        return new Translation(
            id: $record->id, userId: $record->user_id, name: $record->name,
            sourceText: $record->source_text, targetLanguage: $record->target_language,
            status: $record->status, sourceLanguage: $record->source_language,
            detectedLanguage: $record->detected_language, transcriptionId: $record->transcription_id,
            translatedText: $record->translated_text, sourceSegments: $record->source_segments ?? [],
            segments: $record->segments ?? [], exports: $record->exports ?? [],
            exportRevision: $record->export_revision, failureReason: $record->failure_reason,
            createdAt: $record->created_at?->toIso8601String(),
        );
    }

    public function enqueueExports(string $id, int $revision): void
    {
        $event = app(OutboxService::class)->record('translation:'.$id.':exports', 'TranslationExportsRequested', $id, ['revision' => $revision]);
        $event->update(['published_at' => null, 'payload' => ['revision' => $revision],
            'attempts' => $event->published_at !== null ? 0 : $event->attempts]);
    }
}
