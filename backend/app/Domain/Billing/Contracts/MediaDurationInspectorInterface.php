<?php

namespace App\Domain\Billing\Contracts;

interface MediaDurationInspectorInterface
{
    /** @return array{duration_ms: int, audio_url: string, audio_storage_path: string, file_name: string} */
    public function measure(int $userId, array $source, string $quoteId): array;
}
