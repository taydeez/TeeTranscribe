<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Services\RetentionPolicy;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\PrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EloquentPrivacyRepository implements PrivacyRepositoryInterface
{
    private const MODELS = ['transcription' => Transcription::class, 'translation' => Translation::class,
        'dubbing' => Dubbing::class, 'upload' => UploadSession::class, 'quote' => BillingQuote::class];

    public function retention(int $userId): array
    {
        return array_replace(RetentionPolicy::defaults(), User::query()->findOrFail($userId)->privacy_retention ?? []);
    }

    public function saveRetention(int $userId, array $policies): array
    {
        User::query()->findOrFail($userId)->forceFill(['privacy_retention' => $policies])->save();

        return $this->retention($userId);
    }

    public function sourceFilesForUser(int $userId, int $page, int $perPage): array
    {
        $files = [];
        foreach (['upload', 'quote', 'transcription', 'dubbing'] as $type) {
            foreach ($this->query($type)->where('user_id', $userId)->orderByDesc('created_at')->cursor() as $record) {
                $path = $this->sourcePath($record, $type);
                if ($path === null || isset($files[$path]) || $this->sourceDeleted($path) || $this->sourceDeletionPending($path) || ! $this->ownsPath($userId, $path)) {
                    continue;
                }
                $files[$path] = ['id' => (string) $record->id, 'resourceType' => $type,
                    'name' => $record->filename ?? $record->name ?? $record->file_name ?? ($type === 'quote' ? ($record->source['file_name'] ?? basename($path)) : basename($path)),
                    'category' => $this->sourceCategory($path, $record, $type), 'size' => $type === 'upload' ? $record->size : null,
                    'createdAt' => $record->created_at->toIso8601String()];
            }
        }
        usort($files, fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        $total = count($files);

        return ['data' => array_slice($files, ($page - 1) * $perPage, $perPage), 'meta' => [
            'currentPage' => $page, 'lastPage' => max(1, (int) ceil($total / $perPage)), 'perPage' => $perPage, 'total' => $total]];
    }

    public function requestDeletion(int $userId, string $resourceType, string $resourceId, string $scope, ?string $category = null): array
    {
        if ($resourceType === 'folder') {
            return $this->requestFolderDeletion($userId, $resourceId);
        }

        return DB::transaction(function () use ($userId, $resourceType, $resourceId, $scope, $category): array {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $record = $this->query($resourceType, true)->where('user_id', $userId)->lockForUpdate()->find($resourceId)
                ?? throw new BillingException('Item not found.', 404);
            $existing = PrivacyDeletion::query()->where('user_id', $userId)->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)->where('scope', $scope)->where('category', $category)
                ->whereIn('status', ['pending', 'processing', 'failed'])->latest()->first();
            if ($existing !== null) {
                return $this->snapshot($existing);
            }
            if ($scope === 'project' && $record->deleted_at !== null) {
                $completed = PrivacyDeletion::query()->where('user_id', $userId)->where('resource_type', $resourceType)
                    ->where('resource_id', $resourceId)->where('scope', 'project')->latest()->first();
                if ($completed !== null) {
                    return $this->snapshot($completed);
                }
            }
            if ($scope !== 'project' && $record->deleted_at !== null) {
                $completed = PrivacyDeletion::query()->where('user_id', $userId)->where('resource_type', $resourceType)
                    ->where('resource_id', $resourceId)->where('scope', $scope)->where('status', 'completed')->latest()->first();
                if ($completed !== null) {
                    return $this->snapshot($completed);
                }
                throw new BillingException('This project has been deleted.', 410);
            }
            $payload = $this->manifest($record, $resourceType, $scope, $category);
            $this->preserveFileClocks($record, $resourceType);
            if ($scope === 'source' && $resourceType === 'quote' && $payload['source_paths'] === []) {
                throw new BillingException('This request does not have a stored source file yet.', 409);
            }
            foreach ($payload['source_paths'] as $path) {
                if (! $this->ownsPath($userId, $path) || $this->hasForeignOwner($userId, $path)) {
                    if ($scope === 'source') {
                        throw new BillingException('This source cannot be removed from this account.', 409);
                    }
                    $payload['source_paths'] = array_values(array_diff($payload['source_paths'], [$path]));
                }
            }
            $deletion = PrivacyDeletion::query()->create(['user_id' => $userId, 'resource_type' => $resourceType,
                'resource_id' => $resourceId, 'scope' => $scope, 'category' => $category, 'status' => 'pending', 'payload' => $payload]);
            if ($scope === 'project') {
                $record->forceFill(['status' => 'failed'])->save();
                $record->delete();
                if ($resourceType === 'transcription') {
                    TranscriptTool::query()->where('transcription_id', $resourceId)->whereIn('status', ['pending', 'processing'])
                        ->update(['status' => 'failed']);
                }
                $this->scrubQuotesForProject($resourceType, $resourceId);
            } elseif ($scope === 'source') {
                $this->invalidateSourceQuotes($userId, $payload['source_paths']);
                if ($resourceType === 'upload') {
                    $record->delete();
                }
            } else {
                $flags = $record->privacy_deleted_files ?? [];
                foreach ($this->generatedCategories($resourceType, $category) as $format) {
                    $flags[$format] = now()->toIso8601String();
                }
                $record->forceFill(['privacy_deleted_files' => $flags])->save();
            }

            return $this->snapshot($deletion);
        }, 3);
    }

    private function requestFolderDeletion(int $userId, string $id): array
    {
        return DB::transaction(function () use ($userId, $id): array {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = PrivacyDeletion::query()->where('user_id', $userId)->where('resource_type', 'folder')
                ->where('resource_id', $id)->where('scope', 'project')->latest()->first();
            if ($existing !== null) {
                return $this->snapshot($existing);
            }
            $folder = Folder::query()->where('user_id', $userId)->lockForUpdate()->find($id)
                ?? throw new BillingException('Folder not found.', 404);
            $children = [];
            foreach (['transcription' => $folder->transcriptions(), 'translation' => $folder->translations(), 'dubbing' => $folder->dubbings()] as $type => $relation) {
                $table = $relation->getRelated()->getTable();
                foreach ($relation->withTrashed()->where($table.'.user_id', $userId)->get() as $project) {
                    $child = $this->requestDeletion($userId, $type, $project->id, 'project');
                    $payload = $child['payload'];
                    $payload['folder_cascade'] = true;
                    $this->updateDeletion($child['id'], ['payload' => $payload]);
                    $children[] = $child['id'];
                }
            }
            foreach ($children as $childId) {
                $child = $this->findDeletion($childId);
                $payload = $child['payload'];
                $payload['source_paths'] = array_values(array_filter($payload['source_paths'],
                    fn (string $path): bool => ! $this->retainedReference($path, true)));
                $this->updateDeletion($childId, ['payload' => $payload]);
            }
            $deletion = PrivacyDeletion::query()->create(['user_id' => $userId, 'resource_type' => 'folder',
                'resource_id' => $id, 'scope' => 'project', 'status' => 'pending',
                'payload' => ['child_deletions' => $children, 'source_paths' => []]]);
            $folder->translations()->withTrashed()->update(['folder_id' => null]);
            $folder->dubbings()->withTrashed()->update(['folder_id' => null]);
            $folder->delete();

            return $this->snapshot($deletion);
        }, 3);
    }

    public function findDeletion(string $id, ?int $userId = null): ?array
    {
        $record = PrivacyDeletion::query()->when($userId !== null, fn ($query) => $query->where('user_id', $userId))->find($id);

        return $record === null ? null : $this->snapshot($record);
    }

    public function history(int $userId): array
    {
        return PrivacyDeletion::query()->where('user_id', $userId)->latest()->limit(20)->get()->map($this->snapshot(...))->all();
    }

    public function updateDeletion(string $id, array $data): array
    {
        $record = PrivacyDeletion::query()->findOrFail($id);
        $record->update($data);

        return $this->snapshot($record->refresh());
    }

    public function pending(int $limit = 100): array
    {
        return PrivacyDeletion::query()->where(function ($query): void {
            $query->where('status', 'pending')->orWhere(fn ($stale) => $stale->where('status', 'processing')->where('updated_at', '<=', now()->subMinutes(20)));
        })->oldest('updated_at')->limit($limit)->get()->map($this->snapshot(...))->all();
    }

    public function expired(int $limit = 100): array
    {
        $targets = [];
        foreach (User::query()->whereNotNull('privacy_retention')->cursor() as $user) {
            $policies = $this->retention($user->id);
            foreach (['transcription', 'translation', 'dubbing', 'upload', 'quote'] as $type) {
                foreach ($this->query($type)->where('user_id', $user->id)->cursor() as $record) {
                    if (in_array($type, ['transcription', 'translation', 'dubbing'], true) && in_array($record->status, ['pending', 'processing'], true)) {
                        continue;
                    }
                    $textCategory = match ($type) {
                        'transcription' => 'transcripts', 'translation' => 'translations', default => null
                    };
                    if ($textCategory !== null && $this->overdue($record->created_at, $policies[$textCategory])) {
                        $this->appendTarget($targets, $limit, $user->id, $type, $record->id, 'project');
                    } else {
                        $path = $this->sourcePath($record, $type);
                        if ($path !== null && ! $this->sourceDeleted($path) && ! $this->sourceDeletionPending($path)) {
                            $sourceCategory = $this->sourceCategory($path, $record, $type);
                            if ($this->overdue($record->created_at, $policies[$sourceCategory]) && $this->ownsPath($user->id, $path)) {
                                $this->appendTarget($targets, $limit, $user->id, $type, $record->id, 'source');
                            }
                        }
                        foreach ($this->generatedCategories($type) as $format) {
                            if (! $this->generatedDeleted($type, $record->id, $format)
                                && $this->hasGenerated($record, $type, $format)
                                && $this->overdue($this->generatedAt($record, $type, $format), $policies[$format])) {
                                $this->appendTarget($targets, $limit, $user->id, $type, $record->id, 'generated', $format);
                            }
                        }
                    }
                    if (count($targets) >= $limit) {
                        return array_values($targets);
                    }
                }
            }
        }

        return array_values($targets);
    }

    public function readyToPurge(array $deletion): bool
    {
        if ($deletion['resourceType'] === 'folder') {
            return true;
        }
        $payload = $deletion['payload'];
        if ($deletion['scope'] === 'source') {
            foreach ($payload['source_paths'] as $path) {
                if ($this->hasForeignOwner($deletion['userId'], $path)) {
                    throw new BillingException('A shared source cannot be removed from this account.', 409);
                }
                if (Transcription::query()->where('audio_storage_path', $path)->whereIn('status', ['pending', 'processing'])->exists()
                    || Dubbing::query()->where('source_storage_path', $path)->whereIn('status', ['pending', 'processing'])->exists()) {
                    return false;
                }
            }
        } elseif ($deletion['scope'] === 'project') {
            $payload['source_paths'] = array_values(array_filter($payload['source_paths'], fn (string $path): bool => ! $this->retainedReference($path, (bool) ($payload['folder_cascade'] ?? false))));
            $this->updateDeletion($deletion['id'], ['payload' => $payload]);
        } else {
            $record = $this->query($deletion['resourceType'], true)->find($deletion['resourceId']);
            if ($record !== null && in_array($record->status, ['pending', 'processing'], true)) {
                return false;
            }
        }

        return true;
    }

    public function finishDeletion(array $deletion): void
    {
        if ($deletion['resourceType'] === 'folder') {
            return;
        }
        DB::transaction(function () use ($deletion): void {
            $type = $deletion['resourceType'];
            $id = $deletion['resourceId'];
            $record = $this->query($type, true)->find($id);
            if ($record === null) {
                return;
            }
            foreach ($deletion['payload']['source_paths'] as $path) {
                $this->forgetSource($deletion['userId'], $path);
            }
            if ($deletion['scope'] === 'project') {
                $data = ['name' => 'Deleted item', 'privacy_deleted_files' => [], 'source_deleted_at' => now()];
                $data += match ($type) {
                    'transcription' => ['transcript' => null, 'segments' => null, 'audio_path' => '', 'audio_storage_path' => null,
                        'file_name' => 'Deleted item', 'folder_name' => null, 'provider_request_id' => null],
                    'translation' => ['source_text' => '', 'translated_text' => null, 'source_segments' => null, 'segments' => null, 'exports' => null, 'file_generated_at' => null, 'failure_reason' => null],
                    'dubbing' => ['source_storage_path' => '', 'audio_storage_path' => null, 'video_storage_path' => null,
                        'audio_preview_storage_path' => null, 'subtitle_storage_path' => null, 'captioned_video_storage_path' => null,
                        'source_subtitle_segments' => null, 'translated_subtitle_segments' => null, 'provider_options' => null,
                        'provider_project_id' => null, 'provider_language_id' => null, 'file_generated_at' => null, 'failure_reason' => null],
                    default => [],
                };
                $record->forceFill($data)->save();
                if ($type === 'transcription') {
                    TranscriptionExport::query()->where('transcription_id', $id)->delete();
                    TranscriptTool::query()->where('transcription_id', $id)->delete();
                    $record->folders()->detach();
                }
                $this->scrubQuotesForProject($type, $id);
            } elseif ($deletion['scope'] === 'generated') {
                $categories = $this->generatedCategories($type, $deletion['category']);
                if ($type === 'transcription') {
                    TranscriptionExport::query()->where('transcription_id', $id)->whereIn('format', $this->extensions($categories))->delete();
                } elseif ($type === 'translation') {
                    $record->forceFill(['exports' => array_values(array_filter($record->exports ?? [],
                        fn (array $export): bool => ! in_array($export['format'], $this->extensions($categories), true)))])->save();
                } else {
                    $data = [];
                    foreach ($categories as $category) {
                        foreach ($this->dubbingColumns($category) as $column) {
                            $data[$column] = null;
                        }
                    }
                    $record->forceFill($data)->save();
                }
            } elseif ($deletion['payload']['source_paths'] === []) {
                if ($type === 'transcription') {
                    $record->forceFill(['audio_path' => '', 'audio_storage_path' => null, 'source_deleted_at' => now()])->save();
                }
            }
        }, 3);
    }

    public function projectDeleted(string $type, string $id): bool
    {
        return in_array($type, ['transcription', 'translation', 'dubbing', 'upload'], true)
            && $this->query($type, true)->whereKey($id)->whereNotNull('deleted_at')->exists();
    }

    public function sourceDeleted(string $path): bool
    {
        return UploadSession::withTrashed()->where('storage_path', $path)->whereNotNull('source_deleted_at')->exists()
            || $this->sourceIntent($path, ['completed']);
    }

    public function sourceDeletionPending(string $path): bool
    {
        return $this->sourceIntent($path, ['pending', 'processing', 'failed']);
    }

    public function generatedDeleted(string $type, string $id, string $category): bool
    {
        if (! in_array($type, ['transcription', 'translation', 'dubbing'], true)) {
            return false;
        }
        $flags = $this->query($type, true)->find($id)?->privacy_deleted_files ?? [];
        if ($category === 'all_generated' || $category === 'exports') {
            return count(array_intersect($this->generatedCategories($type), array_keys($flags))) === count($this->generatedCategories($type));
        }

        return isset($flags[$category]);
    }

    private function query(string $type, bool $withTrashed = false): Builder
    {
        $class = self::MODELS[$type] ?? throw new BillingException('Unsupported item.', 422);

        return $withTrashed && $type !== 'quote' ? $class::withTrashed() : $class::query();
    }

    private function snapshot(PrivacyDeletion $record): array
    {
        return ['id' => $record->id, 'userId' => $record->user_id, 'resourceType' => $record->resource_type,
            'resourceId' => $record->resource_id, 'scope' => $record->scope, 'category' => $record->category,
            'status' => $record->status, 'attempts' => $record->attempts, 'payload' => $record->payload,
            'failureReason' => $record->failure_reason, 'createdAt' => $record->created_at->toIso8601String(),
            'completedAt' => $record->completed_at?->toIso8601String()];
    }

    private function manifest(Model $record, string $type, string $scope, ?string $category): array
    {
        $payload = ['paths' => [], 'source_paths' => [], 'prefixes' => [], 'format_prefixes' => [], 'google_ids' => [], 'multiparts' => [], 'tool_ids' => []];
        if ($scope !== 'generated') {
            $path = $this->sourcePath($record, $type);
            $payload['source_paths'] = $path === null ? [] : [$path];
        }
        if ($scope === 'source' && $type === 'upload' && $record->status->value === 'uploading' && $record->provider_upload_id !== null) {
            $payload['multiparts'][] = ['path' => $record->storage_path, 'upload_id' => $record->provider_upload_id];
        }
        if ($scope === 'project') {
            $payload['prefixes'] = match ($type) {
                'transcription' => ['exports/'.$record->id.'/', 'transcription-inputs/'.$record->id.'/'],
                'translation' => ['translations/'.$record->id.'/'], 'dubbing' => ['dubbings/'.$record->id.'/'], default => [],
            };
            if ($type === 'transcription') {
                $payload['tool_ids'] = TranscriptTool::query()->where('transcription_id', $record->id)->pluck('id')->all();
                if ($record->provider === 'google') {
                    $payload['google_ids'][] = $record->id;
                }
            }
        } elseif ($scope === 'generated') {
            $categories = $this->generatedCategories($type, $category);
            $prefix = match ($type) {
                'transcription' => 'exports/', 'translation' => 'translations/', 'dubbing' => 'dubbings/'
            };
            $payload['format_prefixes'][] = ['prefix' => $prefix.$record->id.'/', 'extensions' => $this->extensions($categories)];
        }

        return $payload;
    }

    private function sourcePath(Model $record, string $type): ?string
    {
        $path = match ($type) {
            'transcription' => $record->audio_storage_path, 'dubbing' => $record->source_storage_path,
            'upload' => $record->storage_path,
            'quote' => $record->source['audio_storage_path'] ?? $record->source['video_storage_path'] ?? null,
            default => null,
        };

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function ownsPath(int $userId, string $path): bool
    {
        if (UploadSession::withTrashed()->where('user_id', $userId)->where('storage_path', $path)->exists()) {
            return true;
        }
        if (preg_match('~^billing-media/([A-Za-z0-9]{26})\.[a-z0-9]+$~', $path, $match)) {
            return BillingQuote::query()->where('user_id', $userId)->whereKey($match[1])->exists();
        }

        return false;
    }

    private function hasForeignOwner(int $userId, string $path): bool
    {
        return UploadSession::withTrashed()->where('storage_path', $path)->where('user_id', '!=', $userId)->exists()
            || Transcription::query()->where('audio_storage_path', $path)->where('user_id', '!=', $userId)->exists()
            || Dubbing::query()->where('source_storage_path', $path)->where('user_id', '!=', $userId)->exists();
    }

    private function retainedReference(string $path, bool $ignoreSavedUpload = false): bool
    {
        if ((! $ignoreSavedUpload && UploadSession::query()->where('storage_path', $path)->whereNull('source_deleted_at')->exists())
            || Transcription::query()->where('audio_storage_path', $path)->exists()
            || Dubbing::query()->where('source_storage_path', $path)->exists()) {
            return true;
        }
        foreach (BillingQuote::query()->whereIn('status', ['ready', 'measuring'])->where('expires_at', '>', now())->cursor() as $quote) {
            if ($this->sourcePath($quote, 'quote') === $path) {
                return true;
            }
        }

        return false;
    }

    private function sourceIntent(string $path, array $statuses): bool
    {
        foreach (PrivacyDeletion::query()->whereIn('scope', ['source', 'project'])->whereIn('status', $statuses)->cursor() as $deletion) {
            if ($deletion->scope === 'project' && ! ($deletion->payload['folder_cascade'] ?? false)) {
                continue;
            }
            if (in_array($path, $deletion->payload['source_paths'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function forgetSource(int $userId, string $path): void
    {
        foreach (Dubbing::withTrashed()->where('user_id', $userId)->where('source_storage_path', $path)->cursor() as $record) {
            $this->preserveFileClocks($record, 'dubbing');
        }
        UploadSession::withTrashed()->where('user_id', $userId)->where('storage_path', $path)
            ->update(['source_deleted_at' => now(), 'deleted_at' => now(), 'provider_upload_id' => null]);
        Transcription::withTrashed()->where('user_id', $userId)->where('audio_storage_path', $path)
            ->update(['audio_storage_path' => null, 'audio_path' => '', 'source_deleted_at' => now()]);
        Dubbing::withTrashed()->where('user_id', $userId)->where('source_storage_path', $path)
            ->update(['source_storage_path' => '', 'source_deleted_at' => now()]);
        foreach (BillingQuote::query()->where('user_id', $userId)->cursor() as $quote) {
            if ($this->sourcePath($quote, 'quote') === $path) {
                $quote->update(['source' => [], 'request_source' => [], 'status' => 'failed']);
            }
        }
    }

    private function invalidateSourceQuotes(int $userId, array $paths): void
    {
        foreach (BillingQuote::query()->where('user_id', $userId)->whereIn('status', ['ready', 'measuring'])->cursor() as $quote) {
            if (in_array($this->sourcePath($quote, 'quote'), $paths, true)) {
                $quote->update(['status' => 'failed', 'failure_reason' => 'The source file is being deleted.']);
            }
        }
    }

    private function scrubQuotesForProject(string $type, string $id): void
    {
        BillingQuote::query()->where($type.'_id', $id)->update(['source' => json_encode([]), 'request_source' => json_encode([])]);
        if ($type === 'transcription') {
            BillingQuote::query()->whereIn('transcript_tool_id', TranscriptTool::query()->where('transcription_id', $id)->select('id'))
                ->update(['source' => json_encode([]), 'request_source' => json_encode([])]);
        }
    }

    private function sourceCategory(string $path, Model $record, string $type): string
    {
        $upload = $type === 'upload' ? $record : UploadSession::withTrashed()->where('storage_path', $path)->first();
        if ($upload?->source_kind === 'recording') {
            return 'recordings';
        }
        if ($upload?->source_kind === 'video' || str_starts_with($upload?->content_type ?? '', 'video/')
            || ($type === 'dubbing' && $record->media_type === 'video')) {
            return 'source_video';
        }

        return 'source_audio';
    }

    private function generatedCategories(string $type, ?string $category = null): array
    {
        $categories = match ($type) {
            'transcription', 'translation' => ['pdf', 'txt', 'docx', 'subtitles'],
            'dubbing' => ['dubbed_audio', 'dubbed_video', 'subtitles'], default => [],
        };

        return $category === null ? $categories : array_values(array_intersect($categories, [$category]));
    }

    private function extensions(array $categories): array
    {
        $extensions = [];
        foreach ($categories as $category) {
            array_push($extensions, ...match ($category) {
                'subtitles' => ['srt', 'vtt'], 'dubbed_audio' => ['mp3', 'flac', 'wav', 'm4a'],
                'dubbed_video' => ['mp4', 'webm', 'mov'], default => [$category],
            });
        }

        return $extensions;
    }

    private function dubbingColumns(string $category): array
    {
        return match ($category) {
            'dubbed_audio' => ['audio_storage_path', 'audio_preview_storage_path'],
            'dubbed_video' => ['video_storage_path', 'captioned_video_storage_path'],
            'subtitles' => ['subtitle_storage_path'], default => [],
        };
    }

    private function hasGenerated(Model $record, string $type, string $category): bool
    {
        if ($type === 'transcription') {
            return TranscriptionExport::query()->where('transcription_id', $record->id)->where('format', $category)->whereNotNull('storage_path')->exists();
        }
        if ($type === 'translation') {
            foreach ($record->exports ?? [] as $export) {
                if (($export['format'] ?? null) === $category && ! empty($export['storage_path'])) {
                    return true;
                }
            }
        }
        if ($type === 'dubbing') {
            foreach ($this->dubbingColumns($category) as $column) {
                if ($record->{$column} !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function generatedAt(Model $record, string $type, string $category): mixed
    {
        return $type === 'transcription'
            ? TranscriptionExport::query()->where('transcription_id', $record->id)->where('format', $category)->whereNotNull('storage_path')->oldest('updated_at')->first()?->updated_at
            : (isset($record->file_generated_at[$category]) ? CarbonImmutable::parse($record->file_generated_at[$category]) : $record->updated_at);
    }

    private function preserveFileClocks(Model $record, string $type): void
    {
        if (! in_array($type, ['translation', 'dubbing'], true)) {
            return;
        }
        $clocks = $record->file_generated_at ?? [];
        foreach ($this->generatedCategories($type) as $category) {
            if (! isset($clocks[$category]) && $this->hasGenerated($record, $type, $category)) {
                $clocks[$category] = $record->updated_at->toIso8601String();
            }
        }
        $record->forceFill(['file_generated_at' => $clocks])->save();
    }

    private function overdue(mixed $date, ?int $hours): bool
    {
        return $hours !== null && $date !== null && $date->lte(now()->subHours($hours));
    }

    private function appendTarget(array &$targets, int $limit, int $userId, string $type, string $id, string $scope, ?string $category = null): void
    {
        if (count($targets) < $limit) {
            $targets[$userId.':'.$type.':'.$id.':'.$scope.':'.$category] = ['user_id' => $userId,
                'resource_type' => $type, 'resource_id' => $id, 'scope' => $scope, 'category' => $category];
        }
    }
}
