<?php

namespace App\Domain\Translation\Contracts;

use App\Domain\Translation\Entities\Translation;

interface TranslationExportStorageInterface
{
    /** @return list<array{format: string, variant: string, status: string, storage_path: string}> */
    public function generate(Translation $translation): array;

    public function downloadUrl(array $export, string $name): ?string;
}
