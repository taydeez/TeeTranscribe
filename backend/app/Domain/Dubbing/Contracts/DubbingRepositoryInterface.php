<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface DubbingRepositoryInterface
{
    public function find(string $id, ?int $userId = null, bool $lock = false): ?Dubbing;

    public function create(array $data): Dubbing;

    public function update(string $id, array $data): Dubbing;

    public function history(int $userId, int $page, int $perPage): array;

    public function completedUpload(int $userId, string $storagePath): ?array;

    public function enqueueExports(string $id): void;

    public function enqueueSubtitles(string $id, bool $retry = false): void;
}
