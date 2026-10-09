<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing as Record;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;

final class EloquentDubbingRepository implements DubbingRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?Dubbing
    {
        $record = Record::query()->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($lock, fn ($q) => $q->lockForUpdate())->find($id);

        return $record ? $this->entity($record) : null;
    }

    public function create(array $data): Dubbing
    {
        return $this->entity(Record::create($data)->refresh());
    }

    public function update(string $id, array $data): Dubbing
    {
        $record = Record::findOrFail($id);
        $clocks = $record->file_generated_at ?? [];
        foreach (['audio_storage_path' => 'dubbed_audio', 'audio_preview_storage_path' => 'dubbed_audio',
            'video_storage_path' => 'dubbed_video', 'captioned_video_storage_path' => 'dubbed_video', 'subtitle_storage_path' => 'subtitles'] as $column => $category) {
            if (! empty($data[$column])) {
                $clocks[$category] = now()->toIso8601String();
            }
        }
        $data['file_generated_at'] = $clocks;
        $record->update($data);

        return $this->entity($record->refresh());
    }

    public function history(int $userId, int $page, int $perPage): array
    {
        $result = Record::where('user_id', $userId)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        return ['data' => $result->getCollection()->map(fn ($r) => $this->entity($r))->all(), 'meta' => [
            'current_page' => $result->currentPage(), 'last_page' => $result->lastPage(), 'total' => $result->total(), 'per_page' => $perPage]];
    }

    public function completedUpload(int $userId, string $storagePath): ?array
    {
        return UploadSession::where('user_id', $userId)->where('storage_path', $storagePath)->where('status', 'completed')->first()?->only(['filename', 'size', 'content_type']);
    }

    public function enqueueExports(string $id): void
    {
        $event = app(OutboxService::class)->record('dubbing:'.$id.':exports', 'DubbingRequested', $id, []);
        $event->update(['published_at' => null, 'attempts' => $event->published_at !== null ? 0 : $event->attempts]);
    }

    private function entity(Record $r): Dubbing
    {
        return new Dubbing($r->id, $r->user_id, $r->name, $r->source_storage_path, $r->source_language, $r->target_language,
            $r->duration_ms, $r->status, $r->provider_project_id, $r->provider_language_id,
            $r->submission_started_at?->toIso8601String(), $r->provider_completed_at?->toIso8601String(),
            $r->audio_storage_path, $r->video_storage_path, $r->failure_reason, $r->created_at?->toIso8601String(),
            $r->provider, $r->model, $r->provider_options ?? [], $r->subtitles_enabled, $r->subtitle_style, $r->subtitle_status,
            $r->subtitle_storage_path, $r->captioned_video_storage_path, $r->operation, $r->source_subtitle_segments,
            $r->translated_subtitle_segments, $r->detected_source_language, $r->media_type, $r->audio_preview_storage_path, $r->folder_id);
    }

    public function enqueueSubtitles(string $id, bool $retry = false): void
    {
        $event = app(OutboxService::class)->record('dubbing:'.$id.':subtitles', 'DubbingSubtitlesRequested', $id, []);
        if ($retry && $event->published_at !== null) {
            $event->update(['published_at' => null, 'attempts' => 0]);
        }
    }
}
