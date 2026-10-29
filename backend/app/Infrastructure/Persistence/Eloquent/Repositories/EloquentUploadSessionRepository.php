<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Upload\Contracts\UploadSessionRepositoryInterface;
use App\Domain\Upload\Entities\UploadSession as Entity;
use App\Domain\Upload\Enums\UploadStatus;
use App\Domain\Upload\Exceptions\UploadException;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class EloquentUploadSessionRepository implements UploadSessionRepositoryInterface
{
    public function findByClientKey(int $userId, string $key): ?Entity
    {
        $model = UploadSession::withTrashed()->where('user_id', $userId)->where('client_key', $key)->first();
        if ($model?->deleted_at !== null || $model?->source_deleted_at !== null) {
            throw new UploadException('This upload has been deleted. Start a new upload.', 410);
        }

        return $model ? $this->entity($model) : null;
    }

    public function findForUser(string $id, int $userId): ?Entity
    {
        $model = UploadSession::withTrashed()->where('user_id', $userId)->find($id);
        if ($model?->deleted_at !== null || $model?->source_deleted_at !== null) {
            throw new UploadException('This upload has been deleted. Start a new upload.', 410);
        }

        return $model ? $this->entity($model) : null;
    }

    public function create(int $userId, array $data): Entity
    {
        $id = (string) Str::ulid();

        return $this->entity(UploadSession::create([
            'id' => $id, 'user_id' => $userId,
            'client_key' => $data['client_key'], 'filename' => $data['filename'],
            'content_type' => $data['content_type'], 'size' => $data['size'],
            'fingerprint' => $data['fingerprint'],
            'source_kind' => $data['source_kind'] ?? (str_starts_with($data['content_type'], 'video/') ? 'video' : 'audio'),
            'storage_path' => 'audio/'.$id.'.'.strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION)),
            'part_size' => config('uploads.part_bytes'),
            'expires_at' => now()->addDays(config('uploads.expires_days')),
            'status' => UploadStatus::Uploading,
        ]));
    }

    public function save(Entity $session): void
    {
        UploadSession::whereKey($session->id)->update([
            'provider_upload_id' => $session->providerUploadId,
            'status' => $session->status->value,
        ]);
    }

    public function exclusive(string $key, Closure $operation): mixed
    {
        return Cache::lock('multipart:'.$key, 600)->block(10, $operation);
    }

    public function expired(): iterable
    {
        foreach (UploadSession::where('status', UploadStatus::Uploading)->where('expires_at', '<=', now())->lazyById(100) as $model) {
            yield $this->entity($model);
        }
    }

    private function entity(UploadSession $model): Entity
    {
        return new Entity(
            id: $model->id, userId: $model->user_id, clientKey: $model->client_key,
            filename: $model->filename, contentType: $model->content_type, size: $model->size,
            fingerprint: $model->fingerprint, storagePath: $model->storage_path,
            partSize: $model->part_size, expiresAt: $model->expires_at->toDateTimeImmutable(),
            providerUploadId: $model->provider_upload_id, status: $model->status,
        );
    }
}
