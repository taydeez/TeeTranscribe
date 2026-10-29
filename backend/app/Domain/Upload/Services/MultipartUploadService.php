<?php

namespace App\Domain\Upload\Services;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Upload\Contracts\MultipartStorageInterface;
use App\Domain\Upload\Contracts\UploadSessionRepositoryInterface;
use App\Domain\Upload\Entities\UploadSession;
use App\Domain\Upload\Enums\UploadStatus;
use App\Domain\Upload\Exceptions\UploadException;
use DateTimeImmutable;

final class MultipartUploadService
{
    public function __construct(
        private readonly UploadSessionRepositoryInterface $repository,
        private readonly MultipartStorageInterface $storage,
        private readonly PrivacyCoordinatorInterface $privacy,
    ) {}

    public function start(int $userId, array $data): UploadSession
    {
        return $this->repository->exclusive('start:'.$userId.':'.$data['client_key'], function () use ($userId, $data): UploadSession {
            $session = $this->repository->findByClientKey($userId, $data['client_key']);
            if ($session) {
                foreach (['filename', 'size', 'fingerprint'] as $field) {
                    if ($session->{$field} !== $data[$field]) {
                        throw new UploadException('This upload session belongs to a different file.');
                    }
                }
                if ($session->contentType !== $data['content_type']) {
                    throw new UploadException('The file type does not match this upload.');
                }
                $this->assertUsable($session);
            } else {
                $session = $this->repository->create($userId, $data);
            }
            if ($session->providerUploadId === null) {
                $session->providerUploadId = $this->storage->begin($session);
                $this->repository->save($session);
            }

            return $session;
        });
    }

    public function inspect(string $id, int $userId): array
    {
        return $this->repository->exclusive('session:'.$id, function () use ($id, $userId): array {
            $session = $this->find($id, $userId);
            $this->assertUsable($session);
            if ($session->status === UploadStatus::Uploading && $this->storage->verifiedObjectExists($session)) {
                $session->status = UploadStatus::Completed;
                $this->repository->save($session);
            }

            return [$session, $session->status === UploadStatus::Completed ? [] : $this->storage->parts($session)];
        });
    }

    public function signPart(string $id, int $userId, int $number): array
    {
        return $this->repository->exclusive('session:'.$id, function () use ($id, $userId, $number): array {
            $session = $this->find($id, $userId);
            $this->assertUsable($session);
            if ($session->status !== UploadStatus::Uploading || $session->providerUploadId === null) {
                throw new UploadException('This upload is not accepting parts.');
            }
            if ($number < 1 || $number > $session->partCount()) {
                throw new UploadException('Invalid upload part number.', 422);
            }

            return $this->storage->signPart($session, $number);
        });
    }

    public function complete(string $id, int $userId): array
    {
        return $this->privacy->exclusive('upload', $id, fn (): array => $this->completeUpload($id, $userId));
    }

    private function completeUpload(string $id, int $userId): array
    {
        return $this->repository->exclusive('session:'.$id, function () use ($id, $userId): array {
            $session = $this->find($id, $userId);
            $this->assertUsable($session);
            if (! $this->storage->verifiedObjectExists($session)) {
                if ($session->status === UploadStatus::Completed) {
                    throw new UploadException('The completed file is missing from storage.');
                }
                $parts = $this->storage->parts($session);
                usort($parts, fn (array $a, array $b): int => $a['number'] <=> $b['number']);
                if (count($parts) !== $session->partCount()) {
                    throw new UploadException('Some file parts are missing. Resume the upload first.');
                }
                foreach ($parts as $index => $part) {
                    $number = $index + 1;
                    if ($part['number'] !== $number || $part['size'] !== $session->partBytes($number) || $part['etag'] === '') {
                        throw new UploadException('An uploaded part has an incorrect size. Resume the upload to replace it.');
                    }
                }
                $this->storage->complete($session, $parts);
                if (! $this->storage->verifiedObjectExists($session)) {
                    throw new UploadException('The uploaded file could not be verified.');
                }
            }
            $session->status = UploadStatus::Completed;
            $this->repository->save($session);

            return [
                'audio_url' => $this->storage->audioUrl($session),
                'audio_storage_path' => $session->storagePath,
            ];
        });
    }

    public function abort(string $id, int $userId): void
    {
        $this->repository->exclusive('session:'.$id, function () use ($id, $userId): void {
            $session = $this->find($id, $userId);
            if ($session->status === UploadStatus::Completed) {
                throw new UploadException('This file is already uploaded.');
            }
            if ($session->status === UploadStatus::Aborted) {
                return;
            }
            if ($this->storage->verifiedObjectExists($session)) {
                $session->status = UploadStatus::Completed;
                $this->repository->save($session);
                throw new UploadException('This file is already uploaded.');
            }
            if ($session->providerUploadId !== null) {
                $this->storage->abort($session);
            }
            $session->status = UploadStatus::Aborted;
            $this->repository->save($session);
        });
    }

    public function cleanup(): int
    {
        $count = 0;
        foreach ($this->repository->expired() as $session) {
            try {
                $this->abort($session->id, $session->userId);
                $count++;
            } catch (UploadException $exception) {
                if ($exception->httpStatus !== 409) {
                    throw $exception;
                }
            }
        }

        return $count;
    }

    private function find(string $id, int $userId): UploadSession
    {
        return $this->repository->findForUser($id, $userId)
            ?? throw new UploadException('Upload session not found.', 404);
    }

    private function assertUsable(UploadSession $session): void
    {
        if ($session->status === UploadStatus::Aborted) {
            throw new UploadException('This upload was cancelled. Start a new upload.', 410);
        }
        if ($session->expiresAt <= new DateTimeImmutable && $session->status !== UploadStatus::Completed) {
            throw new UploadException('This upload expired. Start a new upload.', 410);
        }
    }
}
