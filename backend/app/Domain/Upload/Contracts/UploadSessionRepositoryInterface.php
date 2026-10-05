<?php

namespace App\Domain\Upload\Contracts;

use App\Domain\Upload\Entities\UploadSession;
use Closure;

interface UploadSessionRepositoryInterface
{
    public function findByClientKey(int $userId, string $key): ?UploadSession;

    public function findForUser(string $id, int $userId): ?UploadSession;

    public function create(int $userId, array $data): UploadSession;

    public function save(UploadSession $session): void;

    public function exclusive(string $key, Closure $operation): mixed;

    /** @return iterable<UploadSession> */
    public function expired(): iterable;
}
