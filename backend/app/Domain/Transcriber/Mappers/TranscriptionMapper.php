<?php

namespace App\Domain\Transcriber\Mappers;

use App\Domain\Transcriber\Contracts\TranscriptionMapperInterface;
use App\Domain\Transcriber\Entities\Transcription;
use DateTimeImmutable;
use DateTimeInterface;

class TranscriptionMapper implements TranscriptionMapperInterface
{
    public function fromArray(array $data): Transcription
    {
        return new Transcription(
            id: $data['id'],
            userId: $data['user_id'] ?? null,
            audioPath: $data['audio_path'],
            fileName: $data['file_name'],
            name: $data['name'],
            folderName: $data['folder_name'] ?? null,
            status: $data['status'] ?? 'pending',
            providerRequestId: $data['provider_request_id'] ?? null,
            transcript: $data['transcript'] ?? null,
            createdAt: $this->date($data['created_at'] ?? null),
            updatedAt: $this->date($data['updated_at'] ?? null),
            guestSessionId: $data['guest_session_id'] ?? null,
            duration: isset($data['duration']) ? (float) $data['duration'] : null,
            provider: $data['provider'] ?? 'deepgram',
        );
    }

    public function toArray(Transcription $transcription): array
    {
        return [
            'id' => $transcription->id,
            'user_id' => $transcription->userId,
            'guest_session_id' => $transcription->guestSessionId,
            'audio_path' => $transcription->audioPath,
            'file_name' => $transcription->fileName,
            'name' => $transcription->name,
            'folder_name' => $transcription->folderName,
            'duration' => $transcription->duration,
            'provider' => $transcription->provider,
            'status' => $transcription->status,
            'provider_request_id' => $transcription->providerRequestId,
            'transcript' => $transcription->transcript,
            'created_at' => $transcription->createdAt?->format('Y-m-d\\TH:i:s.uP'),
            'updated_at' => $transcription->updatedAt?->format('Y-m-d\\TH:i:s.uP'),
        ];
    }

    private function date(DateTimeInterface|string|null $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);
    }
}
