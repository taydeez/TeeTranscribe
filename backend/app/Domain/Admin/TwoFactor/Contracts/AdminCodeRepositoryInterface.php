<?php

namespace App\Domain\Admin\TwoFactor\Contracts;

interface AdminCodeRepositoryInterface
{
    public function create(int $userId, string $codeHash): void;

    public function consumeValid(int $userId, string $codeHash): bool;
}
