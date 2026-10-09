<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderProject;
use App\Domain\Folder\Entities\FolderTranscription;
use App\Domain\Folder\Exceptions\FolderNotFoundException;
use App\Domain\Folder\Exceptions\TranscriptionCannotBeAddedToFolderException;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport as DomainTranscriptionExport;
use App\Infrastructure\Persistence\Eloquent\Models\Folder as FolderModel;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\ProcessPrivacyDeletion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class EloquentFolderRepository implements FolderRepositoryInterface
{
    public function __construct(private readonly PrivacyRepositoryInterface $privacy) {}

    public function paginateForUser(
        int $userId,
        string $search = '',
        string $sort = 'created_at',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 10,
    ): FolderPage {
        $paginator = FolderModel::query()
            ->withCount(['translations', 'dubbings'])
            ->with('transcriptions:id')
            ->where('user_id', $userId)
            ->when($search !== '', fn ($query) => $query->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.mb_strtolower($search).'%'],
            ))
            ->orderBy($sort, $direction)
            ->paginate(perPage: $perPage, page: $page);

        return new FolderPage(
            data: $paginator->getCollection()
                ->map(fn (FolderModel $folder): Folder => $this->toDomain($folder))->all(),
            currentPage: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
        );
    }

    public function findForUser(string $id, int $userId): ?Folder
    {
        $folder = FolderModel::query()->with('transcriptions:id')->withCount(['translations', 'dubbings'])
            ->where('user_id', $userId)->find($id);

        return $folder === null ? null : $this->toDomain($folder);
    }

    public function transcriptionsForUser(string $id, int $userId): array
    {
        $folder = $this->findModelOrFail($id, $userId);

        return $folder->transcriptions()
            ->with('exports')
            ->orderByDesc('transcriptions.created_at')
            ->get()
            ->map(fn (Transcription $transcription): FolderTranscription => new FolderTranscription(
                id: $transcription->id,
                name: $transcription->name,
                fileName: $transcription->file_name,
                status: $transcription->status,
                transcript: $transcription->transcript,
                duration: $transcription->duration,
                provider: $transcription->provider,
                segments: $transcription->segments ?? [],
                audioUrl: $transcription->source_deleted_at === null && in_array($transcription->provider, ['deepgram', 'openai', 'google', 'elevenlabs'], true)
                    ? ($transcription->audio_storage_path !== null
                        ? Storage::disk('r2')->temporaryUrl($transcription->audio_storage_path, now()->addHour())
                        : $transcription->audio_path)
                    : null,
                exports: $transcription->exports->map(
                    fn ($export): DomainTranscriptionExport => new DomainTranscriptionExport(
                        id: $export->id,
                        transcriptionId: $export->transcription_id,
                        format: $export->format,
                        variant: $export->variant,
                        status: $export->status,
                        storagePath: $export->storage_path,
                        failureReason: $export->failure_reason,
                        processingStartedAt: $export->processing_started_at?->toDateTimeImmutable(),
                        createdAt: $export->created_at?->toDateTimeImmutable(),
                        updatedAt: $export->updated_at?->toDateTimeImmutable(),
                    ),
                )->all(),
                createdAt: $transcription->created_at?->toDateTimeImmutable(),
            ))
            ->all();
    }

    public function folderForTranscription(string $transcriptionId, int $userId): ?Folder
    {
        $folder = FolderModel::query()->where('user_id', $userId)
            ->whereHas('transcriptions', fn ($query) => $query->where('transcriptions.id', $transcriptionId)->where('transcriptions.user_id', $userId))
            ->with('transcriptions:id')->withCount(['translations', 'dubbings'])->orderBy('id')->first();

        return $folder === null ? null : $this->toDomain($folder);
    }

    public function projectsForUser(string $id, int $userId): array
    {
        $folder = $this->findModelOrFail($id, $userId);
        $translations = $folder->translations()->where('user_id', $userId)->get()
            ->map(fn ($record): FolderProject => new FolderProject($record->id, $record->name, 'translation', $record->status,
                $record->source_language ?? $record->detected_language, $record->target_language, $record->created_at?->toIso8601String()));
        $dubbings = $folder->dubbings()->where('user_id', $userId)->get()
            ->map(fn ($record): FolderProject => new FolderProject($record->id, $record->name,
                $record->operation === 'subtitles' ? 'subtitles' : 'dubbing', $record->status,
                $record->source_language ?? $record->detected_source_language, $record->target_language,
                $record->created_at?->toIso8601String(), $record->media_type));

        return $translations->concat($dubbings)->sortByDesc('createdAt')->values()->all();
    }

    public function create(int $userId, string $name): Folder
    {
        $folder = FolderModel::query()->create(['user_id' => $userId, 'name' => $name]);

        return $this->toDomain($folder->load('transcriptions:id'));
    }

    public function findOrCreateByName(int $userId, string $name): Folder
    {
        return DB::transaction(function () use ($userId, $name): Folder {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $folder = FolderModel::query()->firstOrCreate(['user_id' => $userId, 'name' => $name]);

            return $this->toDomain($folder->load('transcriptions:id'));
        });
    }

    public function update(string $id, int $userId, string $name): Folder
    {
        $folder = $this->findModelOrFail($id, $userId);
        $folder->update(['name' => $name]);

        return $this->toDomain($folder->refresh()->load('transcriptions:id'));
    }

    public function delete(string $id, int $userId): bool
    {
        $deletion = $this->privacy->requestDeletion($userId, 'folder', $id, 'project');
        if ($deletion['status'] === 'pending' || $deletion['status'] === 'failed') {
            try {
                ProcessPrivacyDeletion::dispatch($deletion['id'])->afterCommit();
            } catch (Throwable $exception) {
                Log::warning('Folder cleanup dispatch deferred.', ['deletion_id' => $deletion['id'], 'exception_type' => $exception::class]);
            }
        }

        return true;
    }

    public function attachTranscription(string $folderId, int $userId, string $transcriptionId): Folder
    {
        $folder = $this->findModelOrFail($folderId, $userId);
        $belongsToUser = Transcription::query()->whereKey($transcriptionId)->where('user_id', $userId)->exists();

        if (! $belongsToUser) {
            throw new TranscriptionCannotBeAddedToFolderException($transcriptionId);
        }

        $folder->transcriptions()->syncWithoutDetaching([$transcriptionId]);

        return $this->toDomain($folder->refresh()->load('transcriptions:id'));
    }

    public function detachTranscription(string $folderId, int $userId, string $transcriptionId): Folder
    {
        $folder = $this->findModelOrFail($folderId, $userId);
        $folder->transcriptions()->detach($transcriptionId);

        return $this->toDomain($folder->refresh()->load('transcriptions:id'));
    }

    private function findModelOrFail(string $id, int $userId): FolderModel
    {
        return FolderModel::query()->where('user_id', $userId)->find($id)
            ?? throw new FolderNotFoundException($id);
    }

    private function toDomain(FolderModel $folder): Folder
    {
        if (! array_key_exists('translations_count', $folder->getAttributes())) {
            $folder->loadCount(['translations', 'dubbings']);
        }

        return new Folder(
            id: $folder->id,
            userId: (int) $folder->user_id,
            name: $folder->name,
            transcriptionIds: $folder->transcriptions->modelKeys(),
            createdAt: $folder->created_at?->toDateTimeImmutable(),
            updatedAt: $folder->updated_at?->toDateTimeImmutable(),
            translationCount: (int) ($folder->translations_count ?? 0),
            dubbingCount: (int) ($folder->dubbings_count ?? 0),
        );
    }
}
