<?php

namespace App\Domain\Dubbing\Contracts;

use App\Domain\Dubbing\Entities\Dubbing;

interface VideoSubtitleTranscriberInterface
{
    /** @return array{provider: string, model: string, configured: bool, source_codes: list<string>} */
    public function definition(): array;

    /** @return array{segments: list<array{start: float, end: float, text: string}>, detected_language: string|null} */
    public function transcribe(Dubbing $record, string $sourceUrl): array;
}
