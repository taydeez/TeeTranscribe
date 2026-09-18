<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Exceptions\TranscriptionNotFoundException;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription as TranscriptionModel;

class EloquentTranscriptionRepository implements TranscriptionRepositoryInterface
{
    public function __construct(private readonly TranscriptionMapperInterface $mapper) {}

    /**
     * @return list<Transcription>
     */
    public function all(): array
    {
        return TranscriptionModel::query()->orderByDesc('id')->get()
            ->map(fn (TranscriptionModel $model): Transcription => $this->mapper->toDomain($model))
            ->all();
    }

    public function find(string $id): ?Transcription
    {
        $model = TranscriptionModel::query()->find($id);

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    /**
     * @param  array{audio_path: string, file_name: string, name: string, folder_name?: string|null, duration?: float|int|null, user_id?: int|null, guest_session_id?: string|null, status?: string, provider_request_id?: string|null, transcript?: string|null}  $data
     */
    public function create(array $data): Transcription
    {
        $model = new TranscriptionModel($data);

        if (! $model->save()) {
            throw new \RuntimeException('The transcription could not be created.');
        }

        return $this->mapper->toDomain($model->refresh());
    }

    /**
     * @param  array{user_id?: int|null, guest_session_id?: string|null, audio_path?: string, file_name?: string, name?: string, folder_name?: string|null, duration?: float|int|null, status?: string, provider_request_id?: string|null, transcript?: string|null}  $data
     */
    public function update(string $id, array $data): Transcription
    {
        $transcription = $this->findModelOrFail($id);

        if (! $transcription->update($data)) {
            throw new \RuntimeException('The transcription could not be updated.');
        }

        return $this->mapper->toDomain($transcription->refresh());
    }

    public function delete(string $id): bool
    {
        return (bool) $this->findModelOrFail($id)->delete();
    }

    private function findModelOrFail(string $id): TranscriptionModel
    {
        return TranscriptionModel::query()->find($id)
            ?? throw new TranscriptionNotFoundException($id);
    }
}
