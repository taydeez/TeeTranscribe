<?php

namespace App\Domain\Privacy\Contracts;

use Closure;

interface PrivacyCoordinatorInterface
{
    public function exclusive(string $type, string $id, Closure $operation): mixed;

    public function projectDeleted(string $type, string $id): bool;

    public function sourceDeleted(string $path): bool;

    public function sourceDeletionPending(string $path): bool;

    public function generatedDeleted(string $type, string $id, string $category): bool;
}
