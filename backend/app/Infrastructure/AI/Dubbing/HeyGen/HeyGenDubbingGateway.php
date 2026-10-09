<?php

namespace App\Infrastructure\AI\Dubbing\HeyGen;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final readonly class HeyGenDubbingGateway implements DubbingGatewayInterface
{
    public function __construct(private HeyGenClient $client) {}

    public function create(Dubbing $record, string $sourceUrl): array
    {
        $body = ['video' => ['type' => 'url', 'url' => $sourceUrl], 'output_languages' => [$record->targetLanguage],
            'title' => 'dubbing:'.$record->id, 'callback_id' => $record->id,
            'mode' => $record->model, 'translate_audio_only' => false,
            'keep_the_same_format' => true, 'enable_dynamic_duration' => true];
        if ($record->sourceLanguage !== null) {
            $body['input_language'] = $record->sourceLanguage;
        }
        $body += array_intersect_key($record->providerOptions, array_flip(['speaker_num', 'disable_music_track', 'enable_speech_enhancement']));
        $result = $this->client->request('POST', '', $body, 'dubbing:'.$record->id);
        $ids = $result['data']['video_translation_ids'] ?? null;
        if (! is_array($ids) || count($ids) !== 1) {
            throw new BillingException('Dubbing returned an invalid submission.', 502);
        }
        $id = $this->id($ids[0]);

        return ['project_id' => $id, 'language_ids' => [$id], 'status' => 'queued'];
    }

    public function recover(string $reference): ?array
    {
        $token = null;
        for ($page = 0; $page < 20; $page++) {
            $result = $this->client->request('GET', '', array_filter(['limit' => 100, 'token' => $token]));
            if (! is_array($result['data'] ?? null)) {
                throw new BillingException('Dubbing returned an invalid listing.', 502);
            }
            foreach ($result['data'] as $item) {
                if (($item['callback_id'] ?? null) === $reference && ($item['title'] ?? null) === 'dubbing:'.$reference) {
                    $id = $this->id($item['id'] ?? null);

                    return ['project_id' => $id, 'language_ids' => [$id]];
                }
            }
            if (! ($result['has_more'] ?? false)) {
                return null;
            }
            $next = $result['next_token'] ?? null;
            if (! is_string($next) || $next === '' || $next === $token) {
                throw new BillingException('Dubbing returned an invalid listing cursor.', 502);
            }
            $token = $next;
        }

        return null;
    }

    public function project(string $id): array
    {
        $data = $this->detail($id);

        return ['project_id' => $id, 'language_ids' => [$id], 'status' => $data['status'] === 'failed' ? 'failed' : 'ready'];
    }

    public function language(string $projectId, string $languageId): array
    {
        if ($projectId !== $languageId) {
            throw new BillingException('Dubbing returned an unexpected translation.', 502);
        }
        $data = $this->detail($projectId);

        return ['project_id' => $projectId, 'language_id' => $languageId, 'target_language' => $data['output_language'] ?? null,
            'status' => $data['status'], 'revision' => 0, 'output_revision' => 0,
            'outputs' => ['video' => $data['video_url'] ?? null]];
    }

    public function languages(): array
    {
        return Cache::remember('heygen:dubbing:languages:'.hash('sha256', (string) config('dubbing.heygen.key')), 3600, function (): array {
            $languages = $this->client->request('GET', '/languages')['data']['languages'] ?? null;
            if (! is_array($languages) || $languages === []) {
                throw new BillingException('Dubbing languages are temporarily unavailable.', 503);
            }
            $items = [];
            foreach ($languages as $name) {
                if (! is_string($name) || trim($name) === '' || strlen($name) > 100) {
                    throw new BillingException('Dubbing returned an invalid language.', 502);
                }
                $items[$name] = ['code' => $name, 'name' => $name, 'nigerian' => in_array($name, ['Yoruba', 'Igbo', 'Hausa'], true)];
            }
            $priority = ['Yoruba' => 0, 'Igbo' => 1, 'Hausa' => 2, 'English' => 3];
            usort($items, fn ($a, $b) => (($priority[$a['name']] ?? 4) <=> ($priority[$b['name']] ?? 4)) ?: strcasecmp($a['name'], $b['name']));

            return array_values($items);
        });
    }

    public function subtitles(Dubbing $record): array
    {
        if ($record->projectId === null) {
            throw new BillingException('The dubbing subtitles are not ready.', 502);
        }
        $data = $this->detail($record->projectId);
        $url = $data['srt_caption_url'] ?? null;
        if ($data['status'] !== 'completed' || ($data['output_language'] ?? null) !== $record->targetLanguage || ! is_string($url) || $url === '') {
            throw new BillingException('The dubbing subtitles are not ready.', 502);
        }

        return ['url' => $url];
    }

    private function detail(string $id): array
    {
        $data = $this->client->request('GET', '/'.$this->id($id))['data'] ?? null;
        if (! is_array($data) || ($data['id'] ?? null) !== $id
            || ! in_array($data['status'] ?? null, ['pending', 'running', 'processing', 'completed', 'failed'], true)) {
            throw new BillingException('Dubbing returned an invalid translation.', 502);
        }
        if ($data['status'] === 'failed') {
            Log::warning('HeyGen dubbing failed.', ['provider_project_id' => $id,
                'reason' => substr(preg_replace('~https?://\S+~', '[url]', (string) ($data['failure_message'] ?? 'Unknown failure')), 0, 2000)]);
        }
        if ($data['status'] === 'completed' && (! is_string($data['video_url'] ?? null) || $data['video_url'] === '')) {
            throw new BillingException('Dubbing returned no completed video.', 502);
        }

        return $data;
    }

    private function id(mixed $id): string
    {
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{1,200}$/D', $id)) {
            throw new BillingException('Dubbing returned an invalid identifier.', 502);
        }

        return $id;
    }
}
