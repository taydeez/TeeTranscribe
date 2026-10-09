<?php

namespace App\Jobs;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PollIntronTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $maxExceptions = 5;

    public CarbonImmutable $pollingDeadline;

    public function __construct(public string $transcriptionId, ?DateTimeInterface $pollingDeadline = null)
    {
        $this->pollingDeadline = $pollingDeadline === null
            ? CarbonImmutable::now()->addMinutes((int) config('transcriber.intron.poll_timeout_minutes', 60))
            : CarbonImmutable::instance($pollingDeadline);
        $this->onConnection('redis')->onQueue('transcriptions');
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->pollingDeadline;
    }

    public function backoff(): array
    {
        return [15, 30, 60];
    }

    public function handle(IntronClient $client, OutboxService $outbox): void
    {
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $this->transcriptionId, function () use ($client, $outbox): void {
            if (! app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
                $this->poll($client, $outbox);
            }
        });
    }

    private function poll(IntronClient $client, OutboxService $outbox): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);
        if ($transcription->provider !== 'intron' || $transcription->status !== 'pending') {
            return;
        }

        if (blank($transcription->provider_request_id)) {
            throw new RuntimeException('The Intron file ID has not been persisted.');
        }

        try {
            $response = $client->status((string) $transcription->provider_request_id);
        } catch (RequestException $exception) {
            if ($exception->response->tooManyRequests()) {
                $this->release($this->retryAfter($exception));

                return;
            }

            throw $exception;
        }

        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
            return;
        }
        $status = strtoupper((string) (data_get($response, 'data.processing_status') ?? data_get($response, 'processing_status')));

        if (in_array($status, ['FILE_QUEUED', 'FILE_PENDING', 'FILE_PROCESSING'], true)) {
            $this->release((int) config('transcriber.intron.poll_interval', 15));

            return;
        }

        if ($status === 'FILE_PROCESSING_FAILED') {
            app(TranscriptionOutcomePublisher::class)->failed($transcription->id, pendingOnly: true);

            return;
        }

        if ($status !== 'FILE_TRANSCRIBED') {
            throw new RuntimeException('Unknown Intron processing status: '.$status);
        }

        $transcript = data_get($response, 'data.audio_transcript');
        if (! is_string($transcript) || trim($transcript) === '') {
            throw new RuntimeException('Intron completed without returning a usable transcript.');
        }

        DB::transaction(function () use ($transcription, $response, $transcript, $outbox): void {
            $record = Transcription::query()->lockForUpdate()->findOrFail($transcription->id);
            if ($record->status !== 'pending') {
                return;
            }

            $duration = data_get($response, 'data.processed_audio_duration_in_seconds');
            $record->update([
                'status' => 'processing',
                'transcript' => trim($transcript),
                'duration' => is_numeric($duration) ? (float) $duration : $record->duration,
            ]);
            app(CreditService::class)->consume(
                $record->id, $record->duration === null ? null : (int) ceil($record->duration * 1000),
            );

            $outbox->record(
                'transcription:'.$record->id.':completed',
                'TranscriptionCompleted',
                $record->id,
                ['transcription_id' => $record->id],
            );
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId, pendingOnly: true);
    }

    private function retryAfter(RequestException $exception): int
    {
        $retryAfter = $exception->response->header('Retry-After');

        return is_numeric($retryAfter)
            ? max(1, min(300, (int) $retryAfter))
            : (int) config('transcriber.intron.poll_interval', 15);
    }
}
