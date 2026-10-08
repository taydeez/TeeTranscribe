<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface DubbingMediaInterface
{
    public function inspect(string $storagePath): array;

    public function sourceUrl(string $storagePath): string;

    public function store(Dubbing $record, string $audioUrl): array;

    public function url(?string $storagePath, string $filename, bool $download = false): ?string;
}
