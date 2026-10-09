<?php

namespace App\Jobs;

use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use App\Domain\Transcriber\Services\TimedTranscriptSegments;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechClient;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PollGoogleTranscription implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $maxExceptions = 5;

    public CarbonImmutable $pollingDeadline;

    public function __construct(public string $transcriptionId)
    {
        $this->pollingDeadline = CarbonImmutable::now()->addMinutes(config('transcriber.google.poll_timeout_minutes', 120));
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

    public function handle(GoogleSpeechClient $client, TranscriptionCompletion $completion, GoogleSpeechAudioStorage $audio): void
    {
        app(PrivacyCoordinatorInterface::class)->exclusive('transcription', $this->transcriptionId, function () use ($client, $completion, $audio): void {
            if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
                return;
            }
            $this->poll($client, $completion, $audio);
        });
    }

    private function poll(GoogleSpeechClient $client, TranscriptionCompletion $completion, GoogleSpeechAudioStorage $audio): void
    {
        $record = Transcription::query()->findOrFail($this->transcriptionId);
        if ($record->provider !== 'google') {
            return;
        }
        if ($record->status !== 'pending') {
            $this->cleanup($audio);

            return;
        }
        if (blank($record->provider_request_id)) {
            throw new RuntimeException('The Google operation has not been saved.');
        }
        $response = $client->status($record->provider_request_id);
        if (app(PrivacyCoordinatorInterface::class)->projectDeleted('transcription', $this->transcriptionId)) {
            return;
        }
        if (! ($response['done'] ?? false)) {
            $this->release(config('transcriber.google.poll_interval', 15));

            return;
        }
        if (isset($response['error'])) {
            Log::warning('Google transcription failed.', ['transcription_id' => $record->id, 'code' => $response['error']['code'] ?? null]);
            app(TranscriptionOutcomePublisher::class)->failed($record->id, pendingOnly: true);
            $this->cleanup($audio);

            return;
        }
        $files = data_get($response, 'response.results', []);
        if (count($files) !== 1) {
            throw new RuntimeException('Google returned no usable transcription result.');
        }
        $file = array_values($files)[0];
        if (isset($file['error'])) {
            app(TranscriptionOutcomePublisher::class)->failed($record->id, pendingOnly: true);
            $this->cleanup($audio);

            return;
        }
        $results = data_get($file, 'inlineResult.transcript.results') ?? data_get($file, 'transcript.results', []);
        $text = [];
        $words = [];
        $speakers = [];
        foreach ($results as $result) {
            $alternative = $result['alternatives'][0] ?? [];
            if (! empty($alternative['transcript'])) {
                $text[] = $alternative['transcript'];
            }
            foreach ($alternative['words'] ?? [] as $word) {
                if (! isset($word['startOffset'], $word['endOffset'])) {
                    continue;
                }
                $label = $word['speakerLabel'] ?? null;
                if ($label !== null) {
                    $speakers[(string) $label] ??= 'Speaker '.(count($speakers) + 1);
                }
                $words[] = [
                    'start' => $this->seconds($word['startOffset']), 'end' => $this->seconds($word['endOffset']),
                    'text' => (string) ($word['word'] ?? ''), 'speaker' => $label === null ? null : $speakers[(string) $label],
                ];
            }
        }
        $completion->complete($record->id, 'google', $record->provider_request_id, implode(' ', $text), TimedTranscriptSegments::fromWords($words));
        $this->cleanup($audio);
    }

    public function failed(?Throwable $exception): void
    {
        app(TranscriptionOutcomePublisher::class)->failed($this->transcriptionId, pendingOnly: true);
        $this->cleanup(app(GoogleSpeechAudioStorage::class));
    }

    private function seconds(string $value): float
    {
        return (float) rtrim($value, 's');
    }

    private function cleanup(GoogleSpeechAudioStorage $audio): void
    {
        try {
            $audio->remove($this->transcriptionId);
        } catch (Throwable $exception) {
            Log::warning('Google transcription staging cleanup failed.', ['transcription_id' => $this->transcriptionId, 'exception_type' => $exception::class]);
        }
    }
}
