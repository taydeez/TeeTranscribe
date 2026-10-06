<?php

namespace App\Infrastructure\Payment\Gateways;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Infrastructure\Payment\Paystack\PayStackClient;

final class PaystackPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly PayStackClient $client) {}

    public function code(): string
    {
        return 'paystack';
    }

    public function webhookIsValid(string $payload, string $signature): bool
    {
        $secret = (string) config('payment.paystack.secret');

        return $secret !== '' && hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    public function referenceFromWebhook(array $payload): ?string
    {
        $reference = $payload['data']['reference'] ?? null;

        return ($payload['event'] ?? '') === 'charge.success' && is_string($reference) && $reference !== '' ? $reference : null;
    }

    public function initialize(array $payment, string $email): string
    {
        $data = $this->client->initialize([
            'email' => $email, 'amount' => $payment['amount_minor'], 'currency' => $payment['currency'],
            'reference' => $payment['reference'],
            'callback_url' => config('payment.paystack.callback_url'),
        ]);
        $url = $data['authorization_url'] ?? null;
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== 'checkout.paystack.com' || parse_url($url, PHP_URL_USER) !== null) {
            throw new BillingException('The payment provider did not return a valid checkout.', 503);
        }

        return $url;
    }

    public function verify(string $reference): array
    {
        return $this->client->verify($reference);
    }
}
