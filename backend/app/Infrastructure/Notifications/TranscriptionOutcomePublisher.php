<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Transcriber\Contracts\TranscriptionOutcomePublisherInterface;
use App\Domain\Transcriber\Events\TranscriptionCompleted;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class TranscriptionOutcomePublisher implements TranscriptionOutcomePublisherInterface
{
    public function completed(string $id): void
    {
        DB::afterCommit(fn () => Event::dispatch(new TranscriptionCompleted($id)));
    }

    public function failed(string $id, bool $pendingOnly = false): void
    {
        DB::transaction(function () use ($id, $pendingOnly): void {
            $record = Transcription::query()->lockForUpdate()->find($id);
            if ($record === null || $record->status === 'complete' || ($pendingOnly && $record->status !== 'pending')) {
                return;
            }
            $record->update(['status' => 'failed']);
            DB::afterCommit(fn () => Event::dispatch(new TranscriptionFailed($id)));
        });
    }
}
