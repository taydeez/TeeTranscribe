<?php

namespace App\Domain\Folder\Contracts;

use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderTranscription;

interface FolderRepositoryInterface
{
    public function paginateForUser(
        int $userId,
        string $search = '',
        string $sort = 'created_at',
        string $direction = 'desc',
        int $page = 1,
        int $perPage = 10,
    ): FolderPage;

    public function findForUser(string $id, int $userId): ?Folder;

    /** @return list<FolderTranscription> */
    public function transcriptionsForUser(string $id, int $userId): array;

    public function create(int $userId, string $name): Folder;

    public function findOrCreateByName(int $userId, string $name): Folder;

    public function update(string $id, int $userId, string $name): Folder;

    public function delete(string $id, int $userId): bool;

    public function attachTranscription(string $folderId, int $userId, string $transcriptionId): Folder;

    public function detachTranscription(string $folderId, int $userId, string $transcriptionId): Folder;
}
