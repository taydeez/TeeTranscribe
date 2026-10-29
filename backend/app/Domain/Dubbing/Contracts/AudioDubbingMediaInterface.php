<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface AudioDubbingMediaInterface
{
    /** @return array{size: int, duration_ms: int} */
    public function inspect(string $storagePath): array;

    /** @return array{audio_storage_path: string, audio_preview_storage_path: string} */
    public function store(Dubbing $record, string $audioUrl): array;
}
