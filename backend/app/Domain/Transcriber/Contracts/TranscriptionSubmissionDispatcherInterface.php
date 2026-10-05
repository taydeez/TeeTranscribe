<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\Transcription;

interface TranscriptionSubmissionDispatcherInterface
{
    public function dispatch(Transcription $transcription, string $languageCode): void;
}
