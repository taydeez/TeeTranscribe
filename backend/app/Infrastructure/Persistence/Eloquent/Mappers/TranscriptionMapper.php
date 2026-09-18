<?php

namespace App\Infrastructure\Persistence\Eloquent\Mappers;

use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface as DomainMapperInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Infrastructure\Persistence\Eloquent\Contracts\TranscriptionMapperInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription as TranscriptionModel;
use InvalidArgumentException;

class TranscriptionMapper implements TranscriptionMapperInterface
{
    public function __construct(private readonly DomainMapperInterface $mapper) {}

    public function toDomain(TranscriptionModel $model): Transcription
    {
        if ($model->getKey() === null) {
            throw new InvalidArgumentException('A transcription must have an ID before mapping to the domain.');
        }

        return $this->mapper->fromArray([
            'id' => $model->id,
            'user_id' => $model->user_id,
            'guest_session_id' => $model->guest_session_id,
            'audio_path' => $model->audio_path,
            'file_name' => $model->file_name,
            'name' => $model->name,
            'folder_name' => $model->folder_name,
            'duration' => $model->duration,
            'status' => $model->status,
            'provider_request_id' => $model->provider_request_id,
            'transcript' => $model->transcript,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ]);
    }

    public function toPersistence(Transcription $transcription): array
    {
        $attributes = $this->mapper->toArray($transcription);
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        return $attributes;
    }
}
