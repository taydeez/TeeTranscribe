<?php

namespace App\Domain\Privacy\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;

final class PrivacyService
{
    public function __construct(private readonly PrivacyRepositoryInterface $repository) {}

    public function settings(int $userId): array
    {
        return ['retention' => RetentionPolicy::merge($this->repository->retention($userId), []),
            'cleanupIntervalMinutes' => RetentionPolicy::CLEANUP_INTERVAL_MINUTES];
    }

    public function updateSettings(int $userId, array $changes): array
    {
        $policies = RetentionPolicy::merge($this->repository->retention($userId), $changes);
        $this->repository->saveRetention($userId, $policies);

        return $this->settings($userId);
    }

    public function files(int $userId, int $page = 1, int $perPage = 20): array
    {
        return $this->repository->sourceFilesForUser($userId, max(1, $page), max(1, min(100, $perPage)));
    }

    public function delete(int $userId, string $type, string $id, string $scope, ?string $category = null): array
    {
        $allowed = match ($type) {
            'folder' => ['project'],
            'upload', 'quote' => ['source'],
            'transcription', 'dubbing' => ['project', 'source', 'generated'],
            'translation' => ['project', 'generated'],
            default => [],
        };
        $formats = match ($type) {
            'transcription' => ['pdf', 'txt', 'docx', 'subtitles'],
            'translation' => ['pdf', 'txt', 'docx', 'subtitles'],
            'dubbing' => ['dubbed_audio', 'dubbed_video', 'subtitles'],
            default => [],
        };
        if (! in_array($scope, $allowed, true) || ($scope === 'generated' && $category !== null && ! in_array($category, $formats, true))) {
            throw new BillingException('This deletion option is not available for this item.', 422);
        }
        if ($scope !== 'generated' && $category !== null) {
            throw new BillingException('Choose a file category only when deleting generated files.', 422);
        }

        return $this->repository->requestDeletion($userId, $type, $id, $scope, $category);
    }

    public function find(string $id, int $userId): array
    {
        return $this->repository->findDeletion($id, $userId)
            ?? throw new BillingException('Deletion request not found.', 404);
    }

    public function history(int $userId): array
    {
        return $this->repository->history($userId);
    }

    public function retry(string $id, int $userId): array
    {
        $deletion = $this->find($id, $userId);
        if ($deletion['status'] !== 'failed') {
            return $deletion;
        }

        return $this->repository->updateDeletion($id, ['status' => 'pending', 'failure_reason' => null]);
    }

    public function queueExpired(int $limit = 100): int
    {
        $count = 0;
        foreach ($this->repository->expired($limit) as $target) {
            try {
                $this->repository->requestDeletion((int) $target['user_id'], $target['resource_type'], $target['resource_id'],
                    $target['scope'], $target['category'] ?? null);
                $count++;
            } catch (BillingException $exception) {
                if ($exception->httpStatus !== 404 && $exception->httpStatus !== 409) {
                    throw $exception;
                }
            }
        }

        return $count;
    }
}
