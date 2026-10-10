<?php

namespace App\Infrastructure\AI;

use App\Domain\AI\Contracts\ProviderCatalogInterface;

final class ConfigProviderCatalog implements ProviderCatalogInterface
{
    public function activities(): array
    {
        return ['transcription' => 'Transcription', 'translation' => 'Translation', 'video_dubbing' => 'Video dubbing',
            'audio_dubbing' => 'Audio dubbing', 'subtitles' => 'Video subtitles', 'cleanup' => 'Transcript cleanup', 'summary' => 'Summaries'];
    }

    public function providers(string $activity): array
    {
        $names = match ($activity) {
            'transcription' => ['deepgram', 'intron', 'google', 'elevenlabs', 'openai'],
            'translation' => ['google', 'openai'], 'video_dubbing' => ['elevenlabs', 'heygen'],
            'audio_dubbing' => ['elevenlabs'], 'subtitles' => ['deepgram'], default => ['openai'],
        };
        $items = [];
        foreach ($names as $provider) {
            $model = $this->model($activity, $provider);
            $billingActivity = in_array($activity, ['video_dubbing', 'audio_dubbing'], true) ? 'dubbing' : $activity;
            $models = array_values(array_unique([$model, ...array_keys(config("billing.rates.{$billingActivity}.{$provider}", []))]));
            $items[$provider] = ['name' => ['deepgram' => 'Deepgram', 'intron' => 'Intron', 'google' => 'Google', 'elevenlabs' => 'ElevenLabs', 'openai' => 'OpenAI', 'heygen' => 'HeyGen'][$provider],
                'model' => $model, 'models' => $models, 'configured' => $this->configured($activity, $provider),
                'model_catalog' => array_map(fn (string $id): array => $this->modelEntry($activity, $provider, $id), $models),
                'custom_models' => ! ($provider === 'intron' || $provider === 'heygen' || ($provider === 'google' && $activity === 'translation') || ($provider === 'elevenlabs' && str_ends_with($activity, '_dubbing')) || ($provider === 'openai' && $activity === 'transcription')),
                'allowed_model_ids' => match (true) {
                    $provider === 'intron' => ['default'],
                    $provider === 'google' && $activity === 'translation' => ['nmt'],
                    $provider === 'heygen' => ['precision', 'speed'],
                    $provider === 'elevenlabs' && str_ends_with($activity, '_dubbing') => ['dubbing_v2'],
                    $provider === 'openai' && $activity === 'transcription' => ['gpt-4o-transcribe-diarize', 'whisper-1', 'gpt-transcribe', 'gpt-4o-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-mini-transcribe-2025-12-15'],
                    default => $models,
                },
                'languages' => $activity === 'transcription' && $provider === 'intron' ? config('transcriber.intron.languages', []) : [],
                'capabilities' => $this->capabilities($activity, $provider)];
        }

        return $items;
    }

    private function modelEntry(string $activity, string $provider, string $id): array
    {
        $billingActivity = str_ends_with($activity, '_dubbing') ? 'dubbing' : $activity;
        $rate = config("billing.rates.{$billingActivity}.{$provider}", [])[$id] ?? [];
        $timed = $provider === 'openai' ? in_array($id, ['whisper-1', 'gpt-4o-transcribe-diarize'], true) : $provider !== 'intron';

        return ['id' => $id, 'label' => $id === 'default' ? 'Provider default' : $id, 'enabled' => true,
            'languages' => $activity === 'transcription' && $provider === 'intron' ? config('transcriber.intron.languages', []) : [],
            'capabilities' => ['speakers' => $activity === 'transcription' && ($provider === 'openai' ? $id === 'gpt-4o-transcribe-diarize' : $provider !== 'intron'),
                'timestamps' => $activity === 'subtitles' || ($activity === 'transcription' && $timed)],
            'pricing' => ['unit' => $rate['unit'] ?? (in_array($activity, ['translation', 'cleanup', 'summary'], true) ? '1000_characters' : 'minute'),
                'credits' => isset($rate['credits']) ? (string) $rate['credits'] : null,
                'provider_cost' => isset($rate['provider_cost']) ? (string) $rate['provider_cost'] : null,
                'provider_currency' => $rate['provider_currency'] ?? 'USD']];
    }

    public function defaults(string $activity): array
    {
        $default = match ($activity) {
            'transcription' => config('transcriber.fallback', 'deepgram'), 'translation' => config('translation.provider', 'google'),
            'video_dubbing' => config('dubbing.provider', 'elevenlabs'), 'audio_dubbing' => config('dubbing.audio_provider', 'elevenlabs'),
            'subtitles' => 'deepgram', default => 'openai',
        };
        $providers = [];
        foreach ($this->providers($activity) as $provider => $definition) {
            $providers[$provider] = ['enabled' => true, 'model' => $definition['model'], 'languages' => []];
        }
        $rules = [];
        if ($activity === 'transcription') {
            foreach (config('transcriber.intron.languages', []) as $language) {
                $rules[strtolower($language)] = 'intron';
            }
            foreach (config('transcriber.language_providers', []) as $language => $provider) {
                $rules[strtolower($language)] = $provider;
            }
        }

        return ['default_provider' => $default, 'providers' => $providers, 'language_rules' => $rules];
    }

    private function model(string $activity, string $provider): string
    {
        return match ($activity) {
            'transcription' => (string) config("transcriber.{$provider}.model", 'default'),
            'translation' => $provider === 'google' ? 'nmt' : (string) config('translation.openai.model', 'gpt-4.1-mini'),
            'video_dubbing', 'audio_dubbing' => $provider === 'heygen' ? (string) config('dubbing.heygen.mode', 'precision') : 'dubbing_v2',
            'subtitles' => 'nova-2', default => (string) config('openai.text_model', 'gpt-4.1-mini'),
        };
    }

    private function configured(string $activity, string $provider): bool
    {
        if ($activity === 'transcription') {
            if ($provider === 'elevenlabs') {
                return filled(config('transcriber.elevenlabs.key')) && filled(config('transcriber.elevenlabs.webhook_id')) && filled(config('transcriber.elevenlabs.webhook_secret'));
            }

            return $provider === 'google' ? filled(config('transcriber.google.project')) && filled(config('transcriber.google.credentials')) && filled(config('transcriber.google.bucket'))
                : filled(config($provider === 'openai' ? 'openai.key' : "transcriber.{$provider}.key"));
        }

        return match ($activity) {
            'translation' => filled(config($provider === 'google' ? 'translation.google.key' : 'openai.key')),
            'video_dubbing', 'audio_dubbing' => filled(config($provider === 'heygen' ? 'dubbing.heygen.key' : 'dubbing.key')),
            'subtitles' => filled(config('transcriber.deepgram.key')) && filled(config('translation.google.key')),
            default => filled(config('openai.key')),
        };
    }

    private function capabilities(string $activity, string $provider): array
    {
        if ($activity !== 'transcription') {
            return match ($activity) {
                'video_dubbing' => ['Video output', ...($provider === 'heygen' ? ['Lip sync'] : [])],
                'audio_dubbing' => ['Audio output'], 'subtitles' => ['Timestamps', 'Translated captions'], default => ['Text output'],
            };
        }

        return match ($provider) {
            'intron' => ['Plain transcript', 'Speaker labels require at least '.config('transcriber.intron.diarization_min_duration', 7200).' seconds'],
            'google' => ['Speaker labels depend on language', 'Word timestamps subject to audio duration limits'],
            'openai' => ['Speaker labels and timestamps depend on model'],
            default => ['Speaker labels', 'Timestamps'],
        };
    }
}
