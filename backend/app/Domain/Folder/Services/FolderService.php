<?php

namespace App\Domain\Folder\Services;

use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderTranscription;
use App\Domain\Folder\Exceptions\FolderNotFoundException;

final class FolderService
{
    public function __construct(private readonly FolderRepositoryInterface $repository) {}

    public function paginateForUser(
        int $userId,
        string $search = '',
        string $sort = 'created_at',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 10,
    ): FolderPage {
        return $this->repository->paginateForUser($userId, $search, $sort, $direction, $page, $perPage);
    }

    public function findOrFail(string $id, int $userId): Folder
    {
        return $this->repository->findForUser($id, $userId) ?? throw new FolderNotFoundException($id);
    }

    /** @return list<FolderTranscription> */
    public function transcriptionsForUser(string $id, int $userId): array
    {
        return $this->repository->transcriptionsForUser($id, $userId);
    }

    public function create(int $userId, string $name): Folder
    {
        return $this->repository->create($userId, $name);
    }

    public function findOrCreateByName(int $userId, string $name): Folder
    {
        return $this->repository->findOrCreateByName($userId, $name);
    }

    public function update(string $id, int $userId, string $name): Folder
    {
        return $this->repository->update($id, $userId, $name);
    }

    public function delete(string $id, int $userId): bool
    {
        return $this->repository->delete($id, $userId);
    }

    public function attachTranscription(string $folderId, int $userId, string $transcriptionId): Folder
    {
        return $this->repository->attachTranscription($folderId, $userId, $transcriptionId);
    }

    public function detachTranscription(string $folderId, int $userId, string $transcriptionId): Folder
    {
        return $this->repository->detachTranscription($folderId, $userId, $transcriptionId);
    }
}
