<?php

namespace App\Infrastructure\Payment\Paystack;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PayStackClient
{
    private function request(): PendingRequest
    {
        $secret = config('payment.paystack.secret');
        if (! is_string($secret) || $secret === '') {
            throw new BillingException('Paystack is not configured.', 503);
        }

        return Http::withToken($secret)->acceptJson()->connectTimeout(5)->timeout(20)
            ->baseUrl(config('payment.paystack.endpoint'));
    }

    public function initialize(array $payload): array
    {
        try {
            $response = $this->request()->post('/transaction/initialize', $payload);
            if (app()->environment('local')) {
                Log::info('Paystack initialization response', [
                    'reference' => $payload['reference'] ?? null,
                    'currency' => $payload['currency'] ?? null,
                    'http_status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);
            }
            $response->throw();
            if ($response->json('status') !== true || ! is_array($response->json('data'))) {
                throw new BillingException('Invalid Paystack checkout response.', 503);
            }

            return $response->json('data');
        } catch (Throwable) {
            throw new BillingException('Checkout is unavailable. Please try again shortly.', 503);
        }
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->request()->get('/transaction/verify/'.rawurlencode($reference))->throw();
            if ($response->json('status') !== true || ! is_array($response->json('data'))) {
                throw new BillingException('Invalid Paystack verification response.', 503);
            }

            return $response->json('data');
        } catch (Throwable) {
            throw new BillingException('Payment verification is unavailable. Your purchase will be checked again.', 503);
        }
    }
}
