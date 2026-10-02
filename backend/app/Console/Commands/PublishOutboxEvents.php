<?php

namespace App\Console\Commands;

use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\GenerateTranscriptionExports;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishOutboxEvents extends Command
{
    /**
     * Execute the console command.
     */
    protected $signature = 'outbox:publish';

    protected $description = 'Publish pending outbox events';

    public function handle(): int
    {
        OutboxEvent::query()
            ->where('event_type', 'TranscriptionCompleted')
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhereExists(function ($transcriptions): void {
                        $transcriptions->selectRaw('1')
                            ->from('transcriptions')
                            ->whereColumn('transcriptions.id', 'outbox_events.aggregate_id')
                            ->whereIn('transcriptions.status', ['processing', 'failed']);
                    });
            })
            ->where(function ($query): void {
                $query->where('attempts', 0)
                    ->orWhere('outbox_events.updated_at', '<=', now()->subMinutes(5));
            })
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {

                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        $transcription = Transcription::query()->find($locked->aggregate_id);
                        if ($transcription === null || $transcription->status === 'complete') {
                            return;
                        }

                        if ($locked->published_at !== null) {
                            $locked->update(['published_at' => null]);
                        }

                        $locked->increment('attempts');
                        GenerateTranscriptionExports::dispatch($locked->aggregate_id, $locked->id)
                            ->afterCommit();
                    });
                }
            });

        return self::SUCCESS;
    }
}
