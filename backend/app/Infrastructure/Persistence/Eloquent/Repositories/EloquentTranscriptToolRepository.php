<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Transcriber\Contracts\TranscriptToolRepositoryInterface;
use App\Domain\Transcriber\Entities\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool as Record;

final class EloquentTranscriptToolRepository implements TranscriptToolRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?TranscriptTool
    {
        $record = Record::query()->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($id);

        return $record === null ? null : $this->entity($record);
    }

    public function forTranscription(string $id, int $userId): array
    {
        return Record::query()->where('transcription_id', $id)->where('user_id', $userId)->orderByDesc('id')->limit(20)->get()
            ->map(fn (Record $record): TranscriptTool => $this->entity($record))->all();
    }

    public function reusable(string $id, int $userId, string $operation, string $sourceHash): ?TranscriptTool
    {
        $record = Record::query()->where('transcription_id', $id)->where('user_id', $userId)
            ->where('operation', $operation)->where('source_hash', $sourceHash)
            ->whereIn('status', ['pending', 'processing', 'complete'])->orderByDesc('id')->first();

        return $record === null ? null : $this->entity($record);
    }

    public function create(array $data): TranscriptTool
    {
        return $this->entity(Record::create($data)->refresh());
    }

    public function update(string $id, array $data): TranscriptTool
    {
        $record = Record::findOrFail($id);
        $record->update($data);

        return $this->entity($record->refresh());
    }

    private function entity(Record $record): TranscriptTool
    {
        return new TranscriptTool($record->id, $record->user_id, $record->transcription_id,
            $record->operation, $record->status, $record->source_hash, $record->source_text,
            $record->source_segments ?? [], $record->model, $record->result, $record->failure_reason, $record->created_at?->toIso8601String(), $record->progress ?? []);
    }
}
