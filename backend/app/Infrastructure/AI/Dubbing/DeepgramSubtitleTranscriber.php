<?php

namespace App\Infrastructure\AI\Dubbing;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\VideoSubtitleTranscriberInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Transcriber\Services\TimedTranscriptSegments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class DeepgramSubtitleTranscriber implements VideoSubtitleTranscriberInterface
{
    public function definition(): array
    {
        return ['provider' => 'deepgram', 'model' => 'nova-2',
            'configured' => filled(config('transcriber.deepgram.key')) && filled(config('translation.google.key')),
            'source_codes' => ['en', 'bg', 'ca', 'zh', 'zh-TW', 'zh-HK', 'cs', 'da', 'nl', 'nl-BE', 'et', 'fi', 'fr', 'de', 'de-CH',
                'el', 'hi', 'hu', 'id', 'it', 'ja', 'ko', 'lv', 'lt', 'ms', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'es', 'sv', 'th', 'tr', 'uk', 'vi']];
    }

    public function transcribe(Dubbing $record, string $sourceUrl): array
    {
        if ($record->provider !== 'deepgram' || $record->model !== 'nova-2' || blank(config('transcriber.deepgram.key'))) {
            throw new BillingException('Subtitle transcription is not configured.', 503);
        }
        $options = ['model' => $record->model, 'punctuate' => 'true', 'smart_format' => 'true', 'utterances' => 'true'];
        $options += $record->sourceLanguage === null ? ['detect_language' => 'true'] : ['language' => $record->sourceLanguage];
        $response = Http::withToken(config('transcriber.deepgram.key'), 'Token')->acceptJson()->connectTimeout(15)->timeout(1800)
            ->withQueryParameters($options)->post(rtrim(config('transcriber.deepgram.endpoint'), '/').'/listen', ['url' => $sourceUrl]);
        if (! $response->successful()) {
            Log::warning('Video subtitle transcription failed.', ['dubbing_id' => $record->id, 'http_status' => $response->status(),
                'error_code' => $response->json('err_code')]);
            throw new BillingException('The video could not be transcribed for subtitles.', 502);
        }
        $segments = [];
        foreach ($response->json('results.utterances', []) as $utterance) {
            $segments[] = ['start' => $utterance['start'] ?? null, 'end' => $utterance['end'] ?? null, 'text' => $utterance['transcript'] ?? null];
        }
        if ($segments === []) {
            $words = [];
            foreach ($response->json('results.channels.0.alternatives.0.words', []) as $word) {
                if (! is_numeric($word['start'] ?? null) || ! is_numeric($word['end'] ?? null) || ! is_string($word['word'] ?? null)) {
                    throw new BillingException('The subtitle transcript has no usable timestamps.', 502);
                }
                $words[] = ['start' => (float) $word['start'], 'end' => (float) $word['end'],
                    'text' => $word['punctuated_word'] ?? $word['word'], 'speaker' => null];
            }
            $segments = TimedTranscriptSegments::fromWords($words);
        }
        $detected = $response->json('results.channels.0.detected_language');

        return ['segments' => $segments, 'detected_language' => is_string($detected) ? $detected : null];
    }
}
