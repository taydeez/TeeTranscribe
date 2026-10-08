<?php

namespace App\Infrastructure\AI\Translation\Gateways;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Infrastructure\AI\Translation\Google\GoogleTranslationClient;
use Illuminate\Support\Facades\Cache;

final class GoogleTranslationGateway implements TranslationGatewayInterface
{
    public function __construct(private readonly GoogleTranslationClient $client) {}

    public function languages(): array
    {
        return Cache::remember('translation:google:nmt:languages:en', 86400, function (): array {
            $items = $this->client->languages()['languages'] ?? null;
            if (! is_array($items) || $items === []) {
                throw new BillingException('Supported languages are temporarily unavailable.', 503);
            }
            $languages = [];
            foreach ($items as $item) {
                if (is_string($item['language'] ?? null) && preg_match('/\A[a-zA-Z-]{2,20}\z/', $item['language']) && is_string($item['name'] ?? null)) {
                    $languages[] = ['code' => $item['language'], 'name' => $item['name']];
                }
            }
            if ($languages === []) {
                throw new BillingException('Supported languages are temporarily unavailable.', 503);
            }

            return $languages;
        });
    }

    public function translate(array $texts, ?string $source, string $target): array
    {
        $chunks = [];
        $owners = [];
        foreach ($texts as $owner => $text) {
            $length = mb_strlen($text, 'UTF-8');
            for ($offset = 0; $offset < max(1, $length);) {
                $chunk = mb_substr($text, $offset, 4500, 'UTF-8');
                if ($offset + 4500 < $length && preg_match('/\s+(?=\S*$)/u', $chunk, $boundary, PREG_OFFSET_CAPTURE)) {
                    $cut = mb_strlen(substr($chunk, 0, $boundary[0][1]), 'UTF-8');
                    if ($cut >= 2000) {
                        $chunk = mb_substr($chunk, 0, $cut + mb_strlen($boundary[0][0], 'UTF-8'), 'UTF-8');
                    }
                }
                $chunks[] = $chunk;
                $owners[] = $owner;
                $offset += max(1, mb_strlen($chunk, 'UTF-8'));
            }
        }
        $output = array_fill(0, count($texts), '');
        $detected = null;
        $batches = [];
        $batch = [];
        $characters = 0;
        foreach ($chunks as $chunk) {
            if ($batch !== [] && (count($batch) >= 128 || $characters + mb_strlen($chunk, 'UTF-8') > 18000 || strlen(json_encode([...$batch, $chunk])) > 90000)) {
                $batches[] = $batch;
                $batch = [];
                $characters = 0;
            }
            $batch[] = $chunk;
            $characters += mb_strlen($chunk, 'UTF-8');
        }
        if ($batch !== []) {
            $batches[] = $batch;
        }
        $processed = 0;
        foreach ($batches as $batch) {
            $items = $this->client->translate($batch, $source, $target)['translations'] ?? null;
            if (! is_array($items) || ! array_is_list($items) || count($items) !== count($batch)) {
                throw new BillingException('The translation provider returned incomplete text.', 502);
            }
            foreach ($items as $index => $item) {
                if (! is_string($item['translatedText'] ?? null) || trim($item['translatedText']) === '') {
                    throw new BillingException('The translation provider returned incomplete text.', 502);
                }
                $owner = $owners[$processed + $index];
                $output[$owner] .= ($output[$owner] !== '' ? ' ' : '').html_entity_decode($item['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $detected ??= is_string($item['detectedSourceLanguage'] ?? null) ? $item['detectedSourceLanguage'] : null;
            }
            $processed += count($batch);
        }

        return ['texts' => $output, 'detected_language' => $detected];
    }
}
