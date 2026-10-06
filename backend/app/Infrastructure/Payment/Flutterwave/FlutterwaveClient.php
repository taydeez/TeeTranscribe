<?php

namespace App\Infrastructure\Payment\Flutterwave;

use App\Domain\Billing\Exceptions\BillingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FlutterwaveClient
{
    private function request(): PendingRequest
    {
        $secret = config('payment.flutterwave.secret');
        if (! is_string($secret) || $secret === '') {
            throw new BillingException('Flutterwave is not configured.', 503);
        }

        return Http::withToken($secret)->acceptJson()->connectTimeout(5)->timeout(20)
            ->baseUrl(config('payment.flutterwave.endpoint'));
    }

    public function initialize(array $payload): array
    {
        try {
            $response = $this->request()->post('/payments', $payload)->throw();
            if ($response->json('status') !== 'success' || ! is_array($response->json('data'))) {
                throw new BillingException('Invalid Flutterwave checkout response.', 503);
            }

            return $response->json('data');
        } catch (Throwable) {
            throw new BillingException('Checkout is unavailable. Please try again shortly.', 503);
        }
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->request()->get('/transactions/verify_by_reference', ['tx_ref' => $reference]);
            if (app()->environment('local')) {
                Log::info('Flutterwave verification response', [
                    'reference' => $reference,
                    'http_status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);
            }
            $response->throw();
            if ($response->json('status') !== 'success' || ! is_array($response->json('data'))) {
                throw new BillingException('Invalid Flutterwave verification response.', 503);
            }

            return $response->json('data');
        } catch (Throwable) {
            throw new BillingException('Payment verification is unavailable. Your purchase will be checked again.', 503);
        }
    }
}
