<?php

namespace App\Domain\Translation\Contracts;

use App\Domain\Translation\Entities\Translation;

interface TranslationRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?Translation;

    public function create(array $data): Translation;

    public function update(string $id, array $data): Translation;

    /** @return array{data: list<Translation>, meta: array<string, int>} */
    public function paginateForUser(int $userId, int $page, int $perPage): array;

    public function sourceForUser(string $transcriptionId, int $userId): ?array;

    public function enqueueExports(string $id, int $revision): void;
}
