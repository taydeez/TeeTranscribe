<?php

namespace App\Infrastructure\AI\Dubbing\HeyGen;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HeyGenClient
{
    public function request(string $method, string $path, array $data = [], ?string $idempotencyKey = null): array
    {
        $key = config('dubbing.heygen.key');
        if (! is_string($key) || trim($key) === '') {
            throw new BillingException('Dubbing is not configured yet.', 503);
        }
        try {
            $request = Http::acceptJson()->asJson()->withHeaders(['x-api-key' => $key])->connectTimeout(10)->timeout(60)->withoutRedirecting();
            if ($idempotencyKey !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }
            $url = 'https://api.heygen.com/v3/video-translations'.$path;
            $response = $method === 'POST' ? $request->post($url, $data) : $request->get($url, $data);
        } catch (\Throwable) {
            throw new BillingException('Dubbing is temporarily unavailable.', 503);
        }
        if (! $response->successful()) {
            Log::warning('HeyGen dubbing request failed.', ['http_status' => $response->status(), 'error_code' => $response->json('error.code')]);
            throw new BillingException('Dubbing is temporarily unavailable.', 503);
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new BillingException('Dubbing returned an invalid response.', 502);
        }

        return $body;
    }
}
