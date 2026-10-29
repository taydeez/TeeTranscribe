<?php

namespace App\Infrastructure\AI\Transcriber\OpenAI;

use App\Domain\Transcriber\Services\OpenAITranscriptionCapabilities;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAITranscriptionClient
{
    /** @return array{request_id: string, text: string, segments: array} */
    public function transcribe(string $path, string $language, string $id, ?string $model = null): array
    {
        $key = config('openai.key');
        $endpoint = rtrim((string) config('openai.endpoint', 'https://api.openai.com/v1/'), '/');
        $parts = parse_url($endpoint);
        if (! is_string($key) || trim($key) === '' || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'api.openai.com' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? null) !== '/v1'
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('OpenAI transcription is not configured.');
        }
        $model ??= (string) config('transcriber.openai.model', 'gpt-4o-transcribe-diarize');
        $format = OpenAITranscriptionCapabilities::format($model);
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > (int) config('transcriber.openai.max_upload_bytes', 24000000)) {
            throw new RuntimeException('The audio exceeds the transcription upload limit.');
        }
        $language = strtolower(explode('-', $language)[0]);
        $data = ['model' => $model, 'response_format' => $format];
        if ($format === 'diarized_json') {
            $data['chunking_strategy'] = 'auto';
            $data['language'] = $language;
        } elseif ($model === 'gpt-transcribe') {
            $data['languages[]'] = $language;
        } else {
            $data['language'] = $language;
            if ($format === 'verbose_json') {
                $data['timestamp_granularities[]'] = 'segment';
            }
        }
        $audio = fopen($path, 'rb');
        if (! is_resource($audio)) {
            throw new RuntimeException('The prepared audio could not be opened.');
        }
        try {
            $response = Http::withToken($key)->acceptJson()->connectTimeout(10)
                ->timeout((int) config('transcriber.openai.request_timeout', 600))
                ->withOptions(['allow_redirects' => false])
                ->attach('file', $audio, 'recording.mp3', ['Content-Type' => 'audio/mpeg'])
                ->post($endpoint.'/audio/transcriptions', $data)->throw();
        } finally {
            fclose($audio);
        }
        $result = $response->json();
        if (! is_array($result) || ! is_string($result['text'] ?? null)
            || (isset($result['segments']) && ! is_array($result['segments']))
            || (OpenAITranscriptionCapabilities::timestamps($model) && ! is_array($result['segments'] ?? null))) {
            throw new RuntimeException('The transcription service returned an invalid response.');
        }
        $segments = [];
        $speakers = [];
        foreach (OpenAITranscriptionCapabilities::timestamps($model) ? ($result['segments'] ?? []) : [] as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $start = $segment['start'] ?? null;
            $end = $segment['end'] ?? null;
            $text = $segment['text'] ?? null;
            if (! is_numeric($start) || ! is_numeric($end) || ! is_finite((float) $start) || ! is_finite((float) $end)
                || (float) $start < 0 || (float) $end < (float) $start || ! is_string($text) || trim($text) === '') {
                continue;
            }
            $speaker = null;
            if ($format === 'diarized_json' && is_string($segment['speaker'] ?? null) && trim($segment['speaker']) !== '') {
                $label = trim($segment['speaker']);
                $speakers[$label] ??= 'Speaker '.(count($speakers) + 1);
                $speaker = $speakers[$label];
            }
            $segments[] = ['start' => (float) $start, 'end' => (float) $end, 'speaker' => $speaker,
                'text' => trim($text), 'confidence' => null];
        }
        usort($segments, fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        $requestId = $response->header('x-request-id');

        return ['request_id' => is_string($requestId) && $requestId !== '' && strlen($requestId) <= 255 ? $requestId : 'openai:'.$id,
            'text' => trim($result['text']), 'segments' => $segments];
    }
}
