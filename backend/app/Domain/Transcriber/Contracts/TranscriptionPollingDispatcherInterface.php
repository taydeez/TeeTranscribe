<?php

namespace App\Domain\Transcriber\Contracts;

use App\Domain\Transcriber\Entities\Transcription;

interface TranscriptionPollingDispatcherInterface
{
    public function dispatch(Transcription $transcription): void;
}
