<?php

namespace App\Infrastructure\Payment\Bachs;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BachsClient
{
    public function initialize(array $payload, string $reference): array
    {
        return $this->send('POST', '/v1/checkout-sessions', $payload, $reference);
    }

    public function checkout(string $id): array
    {
        if (! preg_match('/\Achk_[a-zA-Z0-9_-]{1,128}\z/', $id)) {
            throw new BillingException('Invalid Bachs checkout identifier.', 503);
        }

        return $this->send('GET', '/v1/checkout-sessions/'.$id);
    }

    private function send(string $method, string $path, array $payload = [], ?string $reference = null): array
    {
        $secret = (string) config('payment.bachs.secret');
        if (! str_starts_with($secret, 'sk_live_') && ! str_starts_with($secret, 'sk_sandbox_')) {
            throw new BillingException('Bachs is not configured.', 503);
        }
        $endpoint = str_starts_with($secret, 'sk_sandbox_') ? 'https://sandbox-api.bachs.io' : 'https://api.bachs.io';
        try {
            $request = Http::withToken($secret)->acceptJson()->connectTimeout(5)->timeout(20)->baseUrl($endpoint);
            $response = $method === 'POST'
                ? $request->withHeaders(['Idempotency-Key' => $reference])->post($path, $payload)
                : $request->get($path);
            if (! $response->successful()) {
                Log::warning('Bachs payment request failed.', ['operation' => $method === 'POST' ? 'checkout' : 'verification',
                    'http_status' => $response->status(), 'error_code' => $response->json('error_code')]);
            }
            $response->throw();
            $data = $response->json();
            if (! is_array($data) || ! is_string($data['checkout_id'] ?? null)) {
                throw new BillingException('Invalid Bachs checkout response.', 503);
            }

            return $data;
        } catch (Throwable $exception) {
            Log::warning('Bachs payment operation unavailable.', ['operation' => $method === 'POST' ? 'checkout' : 'verification',
                'exception_type' => $exception::class]);
            throw new BillingException($method === 'POST'
                ? 'Checkout is unavailable. Please try again shortly.'
                : 'Payment verification is unavailable. Your purchase will be checked again.', 503);
        }
    }
}
