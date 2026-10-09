<?php

namespace App\Domain\Privacy\Contracts;

interface PrivacyRepositoryInterface
{
    public function retention(int $userId): array;

    public function saveRetention(int $userId, array $policies): array;

    public function sourceFilesForUser(int $userId, int $page, int $perPage): array;

    public function requestDeletion(int $userId, string $resourceType, string $resourceId, string $scope, ?string $category = null): array;

    public function findDeletion(string $id, ?int $userId = null): ?array;

    public function history(int $userId): array;

    public function updateDeletion(string $id, array $data): array;

    public function pending(int $limit = 100): array;

    public function expired(int $limit = 100): array;

    public function readyToPurge(array $deletion): bool;

    public function finishDeletion(array $deletion): void;

    public function projectDeleted(string $type, string $id): bool;

    public function sourceDeleted(string $path): bool;

    public function sourceDeletionPending(string $path): bool;

    public function generatedDeleted(string $type, string $id, string $category): bool;
}
