<?php

namespace App\Infrastructure\Queue;

use App\Domain\Transcriber\Contracts\TranscriptionSubmissionDispatcherInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Jobs\SubmitTranscription;

class LaravelTranscriptionSubmissionDispatcher implements TranscriptionSubmissionDispatcherInterface
{
    public function dispatch(Transcription $transcription, string $languageCode): void
    {
        SubmitTranscription::dispatch($transcription->id, $languageCode)->afterCommit();
    }
}
