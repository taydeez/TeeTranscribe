<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Entities\TranscriptionEditResult;
use App\Domain\Transcriber\Exceptions\TranscriptionNotFoundException;
use App\Domain\Transcriber\Services\TranscriptionExportOptions;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription as TranscriptionModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @param  array{audio_path: string, audio_storage_path?: string|null, file_name: string, name: string, folder_name?: string|null, duration?: float|int|null, user_id?: int|null, guest_session_id?: string|null, provider?: string, status?: string, provider_request_id?: string|null, transcript?: string|null}  $data
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
     * @param  array{user_id?: int|null, guest_session_id?: string|null, audio_path?: string, audio_storage_path?: string|null, file_name?: string, name?: string, folder_name?: string|null, duration?: float|int|null, status?: string, provider_request_id?: string|null, transcript?: string|null}  $data
     */
    public function update(string $id, array $data): Transcription
    {
        $transcription = $this->findModelOrFail($id);

        if (! $transcription->update($data)) {
            throw new \RuntimeException('The transcription could not be updated.');
        }

        return $this->mapper->toDomain($transcription->refresh());
    }

    public function updateTranscriptForUser(string $id, int $userId, string $transcript, ?array $segments = null): TranscriptionEditResult
    {
        return DB::transaction(function () use ($id, $userId, $transcript, $segments): TranscriptionEditResult {
            $transcription = TranscriptionModel::query()
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->find($id)
                ?? throw new TranscriptionNotFoundException($id);

            $storedSegments = $transcription->segments ?? [];
            if ($segments !== null) {
                if ($transcription->provider !== 'deepgram' || count($segments) !== count($storedSegments) || $storedSegments === []) {
                    throw ValidationException::withMessages(['segments' => 'Timed segments must match the existing Deepgram transcript.']);
                }
                foreach ($storedSegments as $index => &$segment) {
                    $segment['text'] = trim($segments[$index]['text']);
                    $segment['speaker'] = $segments[$index]['speaker'] ?? null;
                }
                unset($segment);
                $transcript = implode("\n", array_column($storedSegments, 'text'));
            } else {
                $storedSegments = [];
            }

            if ($transcription->transcript === $transcript && ($transcription->segments ?? []) === $storedSegments) {
                return new TranscriptionEditResult($this->mapper->toDomain($transcription), false);
            }
            $revision = $transcription->export_revision + 1;
            $transcription->update([
                'transcript' => $transcript,
                'segments' => $storedSegments,
                'status' => 'processing',
                'export_revision' => $revision,
            ]);
            $transcription->exports()->update([
                'status' => 'pending',
                'failure_reason' => null,
                'processing_started_at' => null,
                'export_revision' => $revision,
                'storage_path' => null,
            ]);
            if (! TranscriptionExportOptions::hasSpeakers($storedSegments)) {
                $transcription->exports()->where('variant', 'speakers')->delete();
            }

            return new TranscriptionEditResult($this->mapper->toDomain($transcription->refresh()), true);
        });
    }

    public function delete(string $id): bool
    {
        return (bool) $this->findModelOrFail($id)->delete();
    }

    public function prepareExportsForUser(string $id, int $userId): TranscriptionEditResult
    {
        return DB::transaction(function () use ($id, $userId): TranscriptionEditResult {
            $record = TranscriptionModel::query()->where('user_id', $userId)->lockForUpdate()->find($id)
                ?? throw new TranscriptionNotFoundException($id);
            if (! is_string($record->transcript) || ! in_array($record->status, ['complete', 'processing', 'failed'], true)) {
                throw ValidationException::withMessages(['transcription' => 'This transcript is not ready for export.']);
            }
            $needed = false;
            foreach (TranscriptionExportOptions::required($record->segments ?? []) as $option) {
                $export = $record->exports()->firstOrCreate($option, ['status' => 'pending', 'export_revision' => $record->export_revision]);
                if ($export->export_revision !== $record->export_revision || $export->status !== 'completed' || blank($export->storage_path)) {
                    $export->update(['status' => 'pending', 'export_revision' => $record->export_revision, 'storage_path' => null, 'failure_reason' => null]);
                    $needed = true;
                }
            }
            if ($needed) {
                $record->update(['status' => 'processing']);
            }

            return new TranscriptionEditResult($this->mapper->toDomain($record->refresh()), $needed);
        });
    }

    private function findModelOrFail(string $id): TranscriptionModel
    {
        return TranscriptionModel::query()->find($id)
            ?? throw new TranscriptionNotFoundException($id);
    }
}
