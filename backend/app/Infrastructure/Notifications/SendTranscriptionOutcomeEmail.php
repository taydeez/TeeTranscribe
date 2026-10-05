<?php

namespace App\Infrastructure\Notifications;

use App\Domain\Transcriber\Events\TranscriptionCompleted;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Notifications\TranscriptionOutcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

final class SendTranscriptionOutcomeEmail implements ShouldQueue
{
    public string $connection = 'redis';

    public string $queue = 'default';

    public int $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(TranscriptionCompleted|TranscriptionFailed $event): void
    {
        DB::transaction(function () use ($event): void {
            $record = Transcription::query()->lockForUpdate()->find($event->transcriptionId);
            $completed = $event instanceof TranscriptionCompleted;
            $column = $completed ? 'completion_notified_at' : 'failure_notified_at';
            if ($record === null || $record->status !== ($completed ? 'complete' : 'failed') || $record->{$column} !== null) {
                return;
            }
            $user = $record->user;
            if ($user === null || blank($user->email)) {
                return;
            }
            $folderId = $record->folders()->where('folders.user_id', $user->id)->orderBy('folders.id')->value('folders.id');
            $url = rtrim((string) config('app.frontend_url'), '/').'/dashboard/transcriptions';
            if ($folderId !== null) {
                $url .= '/'.rawurlencode($folderId);
            }
            $user->notify(new TranscriptionOutcomeNotification($record->name, $completed, $url));
            $record->forceFill([$column => now()])->save();
        });
    }
}
