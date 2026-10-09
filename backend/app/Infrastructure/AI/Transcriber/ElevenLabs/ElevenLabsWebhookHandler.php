<?php

namespace App\Infrastructure\AI\Transcriber\ElevenLabs;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\TimedTranscriptSegments;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Illuminate\Support\Facades\DB;

final class ElevenLabsWebhookHandler
{
    public function __construct(private readonly TranscriptionCompletion $completion) {}

    public function handle(array $payload, string $type): void
    {

        if (! in_array($type, ['speech_to_text_transcription', 'speech_to_text_transcription_failed'], true)) {
            return;
        }
        $data = $payload['data'];
        $id = $data['webhook_metadata']['transcription_id'];
        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $id)) {
            return;
        }
        if ($type === 'speech_to_text_transcription_failed') {
            app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $id, function () use ($id, $data): void {
                if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $id)) {
                    return;
                }
                $this->fail($id, $data);
            });

            return;
        }
        $words = [];
        $speakers = [];
        foreach ($data['transcription']['words'] ?? [] as $word) {
            if ($word['type'] !== 'word' || ! isset($word['start'], $word['end'])) {
                continue;
            }
            $speaker = $word['speaker_id'] ?? null;
            if ($speaker !== null) {
                $speakers[$speaker] ??= 'Speaker '.(count($speakers) + 1);
            }
            $words[] = [
                'start' => (float) $word['start'], 'end' => (float) $word['end'],
                'text' => $word['text'] ?? '', 'speaker' => $speaker === null ? null : $speakers[$speaker],
            ];
        }
        $this->completion->complete($id, 'elevenlabs', $data['request_id'], $data['transcription']['text'] ?? '', TimedTranscriptSegments::fromWords($words));

    }

    private function fail(string $id, array $data): void
    {
        DB::transaction(function () use ($id, $data): void {
            $record = Transcription::where('provider', 'elevenlabs')->lockForUpdate()->findOrFail($id);
            abort_if($record->provider_request_id !== null && $record->provider_request_id !== $data['request_id'], 404);
            app(TranscriptionOutcomePublisher::class)->failed($id, pendingOnly: true);
        });

    }
}
