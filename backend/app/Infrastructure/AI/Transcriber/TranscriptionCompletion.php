<?php

namespace App\Infrastructure\AI\Transcriber;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Support\Facades\DB;

final class TranscriptionCompletion
{
    public function __construct(private readonly CreditService $credits, private readonly OutboxService $outbox, private readonly TranscriptionOutcomePublisher $outcomes) {}

    public function complete(string $id, string $provider, string $requestId, string $text, array $segments, ?float $duration = null): void
    {
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $id, function () use ($id, $provider, $requestId, $text, $segments, $duration): void {
            if (! app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $id)) {
                $this->persist($id, $provider, $requestId, $text, $segments, $duration);
            }
        });
    }

    private function persist(string $id, string $provider, string $requestId, string $text, array $segments, ?float $duration): void
    {
        DB::transaction(function () use ($id, $provider, $requestId, $text, $segments, $duration): void {
            $record = Transcription::query()->where('provider', $provider)->lockForUpdate()->findOrFail($id);
            abort_if($record->provider_request_id !== null && $record->provider_request_id !== $requestId, 404);
            if ($record->status !== 'pending') {
                return;
            }
            if (trim($text) === '') {
                $this->outcomes->failed($id, pendingOnly: true);

                return;
            }
            $record->update([
                'provider_request_id' => $requestId, 'status' => 'processing',
                'transcript' => trim($text), 'segments' => $segments,
                'duration' => $duration !== null && $duration > 0 ? $duration : $record->duration,
            ]);
            $this->credits->consume($id, $record->duration === null ? null : (int) ceil($record->duration * 1000));
            $this->outbox->record('transcription:'.$id.':completed', 'TranscriptionCompleted', $id, ['transcription_id' => $id]);
        });
    }
}
