<?php

namespace App\Console\Commands;

use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Jobs\GenerateTranscriptionExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:publish-outbox-events')]
#[Description('Command description')]
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
            ->whereNull('published_at')
            ->where('event_type', 'TranscriptionCompleted')
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {

                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        GenerateTranscriptionExports::dispatch($locked->aggregate_id)
                            ->onConnection('redis')
                            ->onQueue('exports')
                            ->afterCommit();
                        $locked->update(['published_at' => now()]);
                    });
                }
            });

        return self::SUCCESS;
    }
}
