<?php

namespace App\Infrastructure\AI\Dubbing\ElevenLabs;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ElevenLabsClient
{
    public function request(string $method, string $path, array $data = []): array
    {
        $key = config('dubbing.key');
        if (! is_string($key) || trim($key) === '') {
            throw new BillingException('Dubbing is not configured yet.', 503);
        }
        try {
            $request = Http::acceptJson()->withHeaders(['xi-api-key' => $key])->connectTimeout(10)->timeout(60)->withoutRedirecting();
            $url = 'https://api.elevenlabs.io/v1/dubbing/project'.$path;
            $response = $method === 'POST' ? $request->asMultipart()->post($url, $data) : $request->get($url, $data);
        } catch (\Throwable) {
            throw new BillingException('Dubbing is temporarily unavailable.', 503);
        }
        if (! $response->successful()) {
            Log::warning('Dubbing provider request failed.', ['http_status' => $response->status()]);
            throw new BillingException('Dubbing is temporarily unavailable. Check your account configuration.', $response->status() >= 500 ? 503 : 422);
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new BillingException('The dubbing provider returned an invalid response.', 502);
        }

        return $result;
    }
}
