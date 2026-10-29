<?php

namespace App\Infrastructure\AI\Dubbing\Google;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingCleanupInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechAudioStorage;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechClient;
use App\Infrastructure\AI\Translation\Google\GoogleTranslationClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class GoogleDubbingGateway implements DubbingCleanupInterface, DubbingGatewayInterface
{
    public function __construct(private GoogleSpeechClient $speech, private GoogleSpeechAudioStorage $staging,
        private GoogleTextToSpeechClient $voices, private GoogleTranslationClient $translation,
        private GoogleDubbingAudio $audio, private GoogleDubbingSegments $segments) {}

    public function create(Dubbing $record, string $sourceUrl): array
    {
        $this->id($record->id);
        if ($record->sourceLanguage === null) {
            throw new BillingException('Select the spoken language for this dubbing request.', 422);
        }
        if (! Storage::disk('r2')->exists($this->path($record->id))) {
            $this->save($record->id, ['source' => $record->sourceStoragePath, 'source_language' => $record->sourceLanguage,
                'target_language' => $record->targetLanguage, 'duration_ms' => $record->durationMs,
                'voice' => $record->providerOptions['voice'] ?? $this->voices->voice($record->targetLanguage),
                'speech_model' => $record->providerOptions['speech_model'] ?? config('transcriber.google.model', 'chirp_3'),
                'status' => 'processing', 'chunk_id' => $record->id, 'chunk_offset_ms' => 0]);
        }

        return ['project_id' => $record->id, 'language_ids' => [$record->id], 'status' => 'processing'];
    }

    public function recover(string $reference): ?array
    {
        $this->id($reference);

        return Storage::disk('r2')->exists($this->path($reference))
            ? ['project_id' => $reference, 'language_ids' => [$reference], 'status' => 'processing'] : null;
    }

    public function project(string $id): array
    {
        $state = $this->state($id);
        if ($state['status'] !== 'processing') {
            return ['project_id' => $id, 'language_ids' => [$id], 'status' => $state['status'] === 'completed' ? 'ready' : 'failed'];
        }
        if (! isset($state['operation'])) {
            if (! empty($state['submission_started'])) {
                throw new RuntimeException('Google speech submission has an unknown outcome; refusing to submit it twice.');
            }
            $path = $this->audio->stage($id, $state['source'], $state['chunk_offset_ms'], min(1200000, $state['duration_ms'] - $state['chunk_offset_ms']));
            $uri = $this->staging->stage($state['chunk_id'], $path);
            $state['submission_started'] = true;
            $this->save($id, $state);
            $state['operation'] = $this->speech->transcribe($uri, $state['source_language'], $state['speech_model'], true);
            $this->save($id, $state);
        } elseif (! isset($state['segments'])) {
            $result = $this->speech->status($state['operation']);
            if (! ($result['done'] ?? false)) {
                return ['project_id' => $id, 'language_ids' => [$id], 'status' => 'processing'];
            }
            if (isset($result['error'])) {
                Log::warning('Google dubbing speech recognition failed.', ['dubbing_id' => $id,
                    'code' => $result['error']['code'] ?? null, 'message' => $result['error']['message'] ?? null]);
                $state['status'] = 'failed';
                $this->save($id, $state);
                $this->cleanup($id);

                return ['project_id' => $id, 'language_ids' => [$id], 'status' => 'failed'];
            }
            $chunkDuration = min(1200000, $state['duration_ms'] - $state['chunk_offset_ms']);
            $chunkSegments = $this->segments->parse($result, $chunkDuration / 1000, true);
            foreach ($chunkSegments as &$segment) {
                $segment['start'] += $state['chunk_offset_ms'] / 1000;
                $segment['end'] += $state['chunk_offset_ms'] / 1000;
            }
            unset($segment);
            $state['recognition_segments'] = [...($state['recognition_segments'] ?? []), ...$chunkSegments];
            $this->cleanup($id);
            $state['staging_removed'] = true;
            $state['chunk_offset_ms'] += $chunkDuration;
            if ($state['chunk_offset_ms'] < $state['duration_ms']) {
                $state['chunk_id'] = (string) Str::ulid();
                $state['staging_removed'] = false;
                unset($state['operation'], $state['submission_started']);
                $this->save($id, $state);

                return ['project_id' => $id, 'language_ids' => [$id], 'status' => 'processing'];
            }
            $state['segments'] = $state['recognition_segments'];
            if ($state['segments'] === []) {
                throw new RuntimeException('No timestamped speech was found in the dubbing source.');
            }
            unset($state['recognition_segments']);
            $this->save($id, $state);
        }
        if (isset($state['segments'])) {
            $this->cleanup($id);
            $state['staging_removed'] = true;
            $processed = 0;
            foreach (array_slice($state['segments'], $state['next_segment'] ?? 0, null, true) as $index => $segment) {
                if (! isset($segment['translation'])) {
                    $translated = $this->translation->translate([$segment['text']], $this->translationCode($state['source_language']),
                        $this->translationCode($state['target_language']));
                    $text = $translated['translations'][0]['translatedText'] ?? null;
                    if (! is_string($text) || trim($text) === '') {
                        throw new RuntimeException('Google returned an empty dubbing translation.');
                    }
                    $state['segments'][$index]['translation'] = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $this->save($id, $state);
                }
                $path = 'dubbings/'.$id.'/google/segments/'.$index.'.wav';
                if (! Storage::disk('r2')->exists($path)) {
                    $audio = $this->voices->synthesize($state['segments'][$index]['translation'], $state['voice']);
                    if (! Storage::disk('r2')->put($path, $audio, ['ContentType' => 'audio/wav'])
                        || Storage::disk('r2')->size($path) !== strlen($audio)) {
                        throw new RuntimeException('The synthesized speech upload could not be verified.');
                    }
                    $processed++;
                }
                $state['next_segment'] = $index + 1;
                $this->save($id, $state);
                if ($processed >= 10) {
                    return ['project_id' => $id, 'language_ids' => [$id], 'status' => 'processing'];
                }
            }
            $state['audio'] = $this->audio->render($id, $state['segments'], $state['duration_ms']);
            $state['status'] = 'completed';
            $this->save($id, $state);
            Storage::disk('r2')->deleteDirectory('dubbings/'.$id.'/google/segments');
        }

        return ['project_id' => $id, 'language_ids' => [$id], 'status' => $state['status'] === 'completed' ? 'ready' : 'processing'];
    }

    public function language(string $projectId, string $languageId): array
    {
        if ($languageId !== $projectId) {
            throw new RuntimeException('Invalid Google dubbing language reference.');
        }
        $state = $this->state($projectId);

        return ['target_language' => $state['target_language'], 'status' => $state['status'], 'revision' => 1, 'output_revision' => 1,
            'outputs' => isset($state['audio']) ? ['lossless_audio' => $state['audio']] : []];
    }

    public function subtitles(Dubbing $record): array
    {
        $state = $this->state($record->id);
        if ($state['status'] !== 'completed') {
            throw new RuntimeException('Google dubbing subtitles are not ready.');
        }

        return ['segments' => array_map(fn ($segment) => ['start' => $segment['start'], 'end' => $segment['end'],
            'text' => $segment['translation']], $state['segments'])];
    }

    public function cleanup(string $id): void
    {
        $this->id($id);
        if (! Storage::disk('r2')->exists($this->path($id))) {
            return;
        }
        $state = $this->state($id);
        if (! empty($state['staging_removed'])) {
            return;
        }
        $this->staging->remove($state['chunk_id'] ?? $id);
        Storage::disk('r2')->delete('dubbings/'.$id.'/google/source.flac');
        $state['staging_removed'] = true;
        $this->save($id, $state);
    }

    private function translationCode(string $code): string
    {
        return match (explode('-', $code)[0]) {
            'cmn' => 'zh', 'fil' => 'tl', 'nb' => 'no', default => explode('-', $code)[0],
        };
    }

    private function state(string $id): array
    {
        $this->id($id);

        return json_decode(Storage::disk('r2')->get($this->path($id)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function save(string $id, array $state): void
    {
        if (! Storage::disk('r2')->put($this->path($id), json_encode($state, JSON_THROW_ON_ERROR), ['ContentType' => 'application/json'])) {
            throw new RuntimeException('Google dubbing progress could not be saved.');
        }
    }

    private function path(string $id): string
    {
        return 'dubbings/'.$id.'/google/state.json';
    }

    private function id(string $id): void
    {
        if (! preg_match('/^[a-zA-Z0-9]{26}$/D', $id)) {
            throw new RuntimeException('Invalid Google dubbing reference.');
        }
    }
}
