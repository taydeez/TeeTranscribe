<?php

namespace App\Infrastructure\Queue;

use App\Domain\Transcriber\Contracts\TranscriptionPollingDispatcherInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Jobs\PollIntronTranscription;

class LaravelTranscriptionPollingDispatcher implements TranscriptionPollingDispatcherInterface
{
    public function dispatch(Transcription $transcription): void
    {
        if ($transcription->provider !== 'intron') {
            return;
        }

        PollIntronTranscription::dispatch($transcription->id)
            ->delay(now()->addSeconds((int) config('transcriber.intron.initial_poll_delay', 5)))
            ->afterCommit();
    }
}
