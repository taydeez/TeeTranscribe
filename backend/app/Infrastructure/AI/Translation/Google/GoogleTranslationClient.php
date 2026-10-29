<?php

namespace App\Infrastructure\AI\Translation\Google;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GoogleTranslationClient
{
    public function languages(): array
    {
        return $this->request('GET', '/languages', ['target' => 'en', 'model' => 'nmt']);
    }

    public function translate(array $texts, ?string $source, string $target): array
    {
        return $this->request('POST', '', ['q' => $texts, 'target' => $target, 'format' => 'text', 'model' => 'nmt'] + ($source !== null ? ['source' => $source] : []));
    }

    private function request(string $method, string $path, array $data): array
    {
        $key = config('translation.google.key');
        if (! is_string($key) || trim($key) === '') {
            throw new BillingException('Translation is not configured yet.', 503);
        }
        try {
            $client = Http::acceptJson()->connectTimeout(10)->timeout(45)->withQueryParameters(['key' => $key]);
            $url = 'https://translation.googleapis.com/language/translate/v2'.$path;
            $response = $method === 'GET' ? $client->get($url, $data) : $client->post($url, $data);
        } catch (\Throwable) {
            throw new BillingException('Translation is temporarily unavailable. Please try again.', 503);
        }
        if (! $response->successful()) {
            Log::warning('Translation provider request failed.', ['http_status' => $response->status(), 'error_status' => $response->json('error.status')]);
            throw new BillingException('Translation is temporarily unavailable. Please try again.', 503);
        }
        $result = $response->json('data');
        if (! is_array($result)) {
            throw new BillingException('The translation provider returned an invalid response.', 502);
        }

        return $result;
    }
}
