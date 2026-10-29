<?php

namespace App\Domain\Privacy\Services;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Contracts\PrivacyStorageInterface;
use Closure;
use DateTimeImmutable;
use Throwable;

final class ProcessPrivacyDeletion
{
    public function __construct(
        private readonly PrivacyRepositoryInterface $repository,
        private readonly PrivacyStorageInterface $storage,
        private readonly PrivacyCoordinatorInterface $coordinator,
        private readonly CreditService $credits,
    ) {}

    /** Return false while a source is still required by active processing. */
    public function handle(string $id): bool
    {
        $deletion = $this->repository->findDeletion($id);
        if ($deletion === null || $deletion['status'] === 'completed') {
            return true;
        }

        $sources = array_values(array_unique($deletion['payload']['source_paths'] ?? []));
        sort($sources);

        return $this->withSourceLocks($sources, fn (): bool => $this->coordinator->exclusive($deletion['resourceType'], $deletion['resourceId'], function () use ($id): bool {
            $deletion = $this->repository->findDeletion($id);
            if ($deletion === null || $deletion['status'] === 'completed') {
                return true;
            }
            try {
                if (! $this->repository->readyToPurge($deletion)) {
                    $this->repository->updateDeletion($id, ['status' => 'pending']);

                    return false;
                }
                $deletion = $this->repository->findDeletion($id);
                $this->repository->updateDeletion($id, ['status' => 'processing',
                    'attempts' => ($deletion['attempts'] ?? 0) + 1, 'failure_reason' => null]);
                if ($deletion['scope'] === 'project') {
                    match ($deletion['resourceType']) {
                        'transcription' => $this->credits->release($deletion['resourceId']),
                        'translation' => $this->credits->releaseTranslation($deletion['resourceId']),
                        'dubbing' => $this->credits->releaseDubbing($deletion['resourceId']),
                        default => null,
                    };
                    foreach ($deletion['payload']['tool_ids'] ?? [] as $toolId) {
                        $this->credits->releaseTool($toolId);
                    }
                }
                foreach ($deletion['payload']['child_deletions'] ?? [] as $childId) {
                    if (! $this->handle($childId)) {
                        $this->repository->updateDeletion($id, ['status' => 'pending']);

                        return false;
                    }
                }
                if ($deletion['resourceType'] !== 'folder') {
                    $this->storage->purge($deletion['payload']);
                }
                $this->repository->finishDeletion($deletion);
                $this->repository->updateDeletion($id, ['status' => 'completed', 'failure_reason' => null,
                    'completed_at' => (new DateTimeImmutable)->format('c')]);

                return true;
            } catch (Throwable $exception) {
                $this->repository->updateDeletion($id, ['status' => 'failed',
                    'failure_reason' => 'Some files could not be removed. Retry to finish deleting this item.']);

                throw $exception;
            }
        }));
    }

    private function withSourceLocks(array $paths, Closure $operation): bool
    {
        if ($paths === []) {
            return $operation();
        }
        $path = array_shift($paths);

        return $this->coordinator->exclusive('source', $path, fn (): bool => $this->withSourceLocks($paths, $operation));
    }
}
