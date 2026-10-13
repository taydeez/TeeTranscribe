<?php

namespace App\Infrastructure\AI\Dubbing\Google;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Dubbing\Services\DubbingLanguages;
use App\Infrastructure\AI\Transcriber\Google\GoogleSpeechCredentials;
use App\Infrastructure\Cache\ReadCache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class GoogleTextToSpeechClient
{
    public function __construct(private GoogleSpeechCredentials $credentials, private ReadCache $cache) {}

    public function voices(): array
    {
        return $this->cache->remember('languages:google-dubbing:'.hash('sha256', (string) config('transcriber.google.credentials')), 3600, function (): array {
            try {
                $voices = Http::withToken($this->credentials->token())->connectTimeout(10)->timeout(45)
                    ->get('https://texttospeech.googleapis.com/v1/voices')->throw()->json('voices');
            } catch (\Throwable) {
                throw new BillingException('The dubbing language catalog is temporarily unavailable.', 503);
            }
            if (! is_array($voices)) {
                throw new BillingException('The dubbing language catalog is temporarily unavailable.', 503);
            }

            return array_values(array_filter($voices, fn ($voice) => is_string($voice['name'] ?? null)
                && str_contains($voice['name'], '-Chirp3-HD-') && is_array($voice['languageCodes'] ?? null)));
        });
    }

    public function voice(string $language): array
    {
        $language = match ($language) {
            'en' => 'en-US', 'es' => 'es-ES', 'fr' => 'fr-FR', 'pt' => 'pt-BR', 'zh', 'cmn' => 'cmn-CN',
            'no' => 'nb-NO', 'he' => 'he-IL', default => $language,
        };
        foreach ([true, false] as $exact) {
            foreach ($this->voices() as $voice) {
                foreach ($voice['languageCodes'] as $locale) {
                    $matches = $exact ? strcasecmp($locale, $language) === 0 : explode('-', $locale)[0] === explode('-', $language)[0];
                    if ($matches && str_ends_with($voice['name'], '-'.config('dubbing.google.voice', 'Aoede'))) {
                        return ['name' => $voice['name'], 'languageCode' => $locale];
                    }
                }
            }
        }
        throw new BillingException('The selected dubbing language has no configured Google voice.', 422);
    }

    public function languages(): array
    {
        $items = [];
        foreach ((new DubbingLanguages)->sourceLanguages() as $language) {
            try {
                $this->voice($language['code']);
                $items[] = $language;
            } catch (BillingException) {
                continue;
            }
        }

        return $items;
    }

    public function synthesize(string $text, array $voice): string
    {
        if (trim($text) === '' || strlen($text) > 4500) {
            throw new RuntimeException('The translated speech exceeds the synthesis limit.');
        }
        $response = Http::withToken($this->credentials->token())->connectTimeout(10)->timeout(90)
            ->post('https://texttospeech.googleapis.com/v1/text:synthesize', [
                'input' => ['text' => $text], 'voice' => $voice,
                'audioConfig' => ['audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 24000],
            ])->throw();
        $encoded = $response->json('audioContent');
        $audio = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($audio) || strlen($audio) < 44 || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
            throw new RuntimeException('Google returned invalid synthesized audio.');
        }

        return $audio;
    }
}
