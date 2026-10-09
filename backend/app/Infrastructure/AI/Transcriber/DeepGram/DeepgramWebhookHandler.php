<?php

namespace App\Infrastructure\AI\Transcriber\DeepGram;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DeepgramWebhookHandler
{
    public function __construct(private readonly OutboxService $outbox, private readonly PrivacyCoordinatorInterface $privacy) {}

    public function handle(array $data, string $transcription): void
    {
        $this->privacy->exclusive('transcription', $transcription, function () use ($data, $transcription): void {
            if ($this->privacy->projectDeleted('transcription', $transcription)) {
                return;
            }
            $this->receive($data, $transcription);
        });
    }

    private function receive(array $data, string $transcription): void
    {

        Log::info('Deepgram webhook received', ['transcription' => $transcription]);

        try {
            DB::transaction(function () use ($data, $transcription): void {
                $record = Transcription::query()
                    ->whereKey($transcription)
                    ->where('provider_request_id', $data['metadata']['request_id'])
                    ->where('provider', 'deepgram')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($record->status !== 'pending') {
                    return;
                }

                $record->status = 'processing';
                $record->transcript = $data['results']['channels'][0]['alternatives'][0]['transcript'] ?? '';
                $record->segments = array_map(fn (array $utterance): array => [
                    'start' => (float) $utterance['start'],
                    'end' => (float) $utterance['end'],
                    'speaker' => isset($utterance['speaker']) ? 'Speaker '.($utterance['speaker'] + 1) : null,
                    'text' => $utterance['transcript'],
                    'confidence' => isset($utterance['confidence']) ? (float) $utterance['confidence'] : null,
                ], $data['results']['utterances'] ?? []);

                if (isset($data['metadata']['duration'])) {
                    $record->duration = (float) $data['metadata']['duration'];
                }

                if (! $record->save()) {
                    throw new RuntimeException('The transcription could not be moved to processing.');
                }

                if (trim($record->transcript) === '') {
                    app(TranscriptionOutcomePublisher::class)->failed($record->id);

                    return;
                }
                app(CreditService::class)->consume(
                    $record->id, $record->duration === null ? null : (int) ceil($record->duration * 1000),
                );

                $this->outbox->record(
                    eventKey: "transcription:{$record->id}:completed",
                    eventType: 'TranscriptionCompleted',
                    aggregateId: $record->id,
                    payload: ['transcription_id' => $record->id],
                );
            }, attempts: 3);
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            app(TranscriptionOutcomePublisher::class)->failed($transcription);

            throw $exception;
        }

    }
}
