<?php

namespace App\Console\Commands;

use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Payment;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\GeneratePaymentInvoice;
use App\Jobs\GenerateTranscriptionExports;
use App\Jobs\MeasureBillingQuote;
use App\Jobs\MeasureDubbingQuote;
use App\Jobs\ProcessDubbing;
use App\Jobs\ProcessTranscriptTool;
use App\Jobs\ProcessTranslation;
use App\Jobs\RenderDubbingSubtitles;
use App\Jobs\SendAccountEmail;
use App\Jobs\SubmitTranscription;
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
        OutboxEvent::query()->whereIn('event_type', ['AccountRegistered', 'CreditsReserved', 'CreditsReturned'])->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinutes(5)))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        SendAccountEmail::dispatch($locked->id)->afterCommit();
                    });
                }
            });
        OutboxEvent::query()->whereIn('event_type', ['DubbingQuoteRequested', 'DubbingRequested', 'DubbingSubtitlesRequested'])->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinute()))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        if ($locked->event_type === 'DubbingQuoteRequested') {
                            MeasureDubbingQuote::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                        } elseif ($locked->event_type === 'DubbingSubtitlesRequested') {
                            RenderDubbingSubtitles::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                        } else {
                            ProcessDubbing::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                        }
                    });
                }
            });
        OutboxEvent::query()->whereIn('event_type', ['TranslationSubmitted', 'TranslationExportsRequested'])->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinutes(5)))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        ProcessTranslation::dispatch($locked->aggregate_id, $locked->id, $locked->payload['revision'] ?? 0)->afterCommit();
                    });
                }
            });
        OutboxEvent::query()->where('event_type', 'TranscriptToolRequested')->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinutes(5)))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        ProcessTranscriptTool::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                    });
                }
            });
        Payment::query()->where('status', 'paid')->whereNull('invoice_notified_at')
            ->whereNotExists(function ($events): void {
                $events->selectRaw('1')->from('outbox_events')
                    ->whereColumn('outbox_events.aggregate_id', 'payments.id')
                    ->where('outbox_events.event_type', 'PaymentConfirmed');
            })
            ->orderBy('id')->limit(100)->pluck('id')->each(function (string $paymentId): void {
                app(OutboxService::class)->record('payment:'.$paymentId.':confirmed', 'PaymentConfirmed', $paymentId, ['payment_id' => $paymentId]);
            });

        OutboxEvent::query()->where('event_type', 'PaymentConfirmed')->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinutes(5)))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        GeneratePaymentInvoice::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                    });
                }
            });

        OutboxEvent::query()->whereIn('event_type', ['BillingQuoteRequested', 'TranscriptionSubmitted'])
            ->whereNull('published_at')
            ->where(fn ($query) => $query->where('attempts', 0)->orWhere('updated_at', '<=', now()->subMinutes(5)))
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    DB::transaction(function () use ($event): void {
                        $locked = OutboxEvent::query()->lockForUpdate()->findOrFail($event->id);
                        if ($locked->published_at !== null) {
                            return;
                        }
                        $locked->increment('attempts');
                        if ($locked->event_type === 'BillingQuoteRequested') {
                            MeasureBillingQuote::dispatch($locked->aggregate_id, $locked->id)->afterCommit();
                        } else {
                            SubmitTranscription::dispatch(
                                $locked->aggregate_id, $locked->payload['language_code'], $locked->id,
                                $locked->payload['model'] ?? null,
                            )->afterCommit();
                        }
                    });
                }
            });

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
