<?php

namespace App\Domain\Dubbing\Contracts;

interface DubbingCleanupInterface
{
    public function cleanup(string $id): void;
}
