<?php

namespace App\Jobs;

use App\Infrastructure\AI\Transcriber\Intron\IntronClient;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PollIntronTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 30;

    public int $timeout = 120;

    public function __construct(public string $transcriptionId)
    {
        $this->onConnection('redis')->onQueue('transcriptions');
    }

    public function backoff(): array
    {
        return [15, 30, 60];
    }

    public function handle(IntronClient $client, OutboxService $outbox): void
    {
        $transcription = Transcription::query()->findOrFail($this->transcriptionId);
        if ($transcription->provider !== 'intron' || $transcription->status === 'complete') {
            return;
        }

        $response = $client->status((string) $transcription->provider_request_id);
        $status = strtoupper((string) (data_get($response, 'data.processing_status') ?? data_get($response, 'processing_status')));

        if (in_array($status, ['FILE_QUEUED', 'FILE_PENDING', 'FILE_PROCESSING'], true)) {
            $this->release(15);

            return;
        }

        if ($status === 'FILE_PROCESSING_FAILED') {
            $transcription->update(['status' => 'failed']);
            throw new RuntimeException('Intron transcription processing failed.');
        }

        if ($status !== 'FILE_TRANSCRIBED') {
            throw new RuntimeException('Unknown Intron processing status: '.$status);
        }

        DB::transaction(function () use ($transcription, $response, $outbox): void {
            $record = Transcription::query()->lockForUpdate()->findOrFail($transcription->id);
            if ($record->status !== 'pending') {
                return;
            }

            $transcript = data_get($response, 'data.audio_transcript');
            $record->update([
                'status' => 'processing',
                'transcript' => is_string($transcript) ? $transcript : json_encode($transcript, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'duration' => data_get($response, 'data.processed_audio_duration_in_seconds', $record->duration),
            ]);

            $outbox->record(
                'transcription:'.$record->id.':completed',
                'TranscriptionCompleted',
                $record->id,
                ['transcription_id' => $record->id],
            );
        });
    }
}
