<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\TranscriptionExport;

interface TranscriptionExportUrlGeneratorInterface
{
    public function generate(TranscriptionExport $export, string $transcriptionName): ?string;
}
