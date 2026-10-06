<?php

namespace App\Infrastructure\Payment\Gateways;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Infrastructure\Payment\Flutterwave\FlutterwaveClient;
use Illuminate\Support\Facades\Log;

final class FlutterwavePaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly FlutterwaveClient $client) {}

    public function code(): string
    {
        return 'flutterwave';
    }

    public function webhookIsValid(string $payload, string $signature): bool
    {
        $secret = (string) config('payment.flutterwave.secret_hash');

        return $secret !== '' && hash_equals($secret, $signature);
    }

    public function referenceFromWebhook(array $payload): ?string
    {
        $reference = $payload['data']['tx_ref'] ?? null;

        return ($payload['event'] ?? '') === 'charge.completed' && is_string($reference) && $reference !== '' ? $reference : null;
    }

    public function initialize(array $payment, string $email): string
    {
        $units = (int) $payment['amount_minor'];
        $data = $this->client->initialize([
            'tx_ref' => $payment['reference'],
            'amount' => intdiv($units, 100).'.'.str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT),
            'currency' => $payment['currency'],
            'redirect_url' => config('payment.flutterwave.callback_url'),
            'customer' => ['email' => $email],
            'customizations' => ['title' => 'TeeTranscribe credits'],
        ]);
        $url = $data['link'] ?? null;
        $hosts = ['checkout.flutterwave.com'];
        if (($payment['environment'] ?? null) === 'test') {
            $hosts[] = 'checkout-v2.dev-flutterwave.com';
        }
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! in_array(parse_url($url, PHP_URL_HOST), $hosts, true)
            || parse_url($url, PHP_URL_USER) !== null
            || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)) {
            $parts = is_string($url) ? parse_url($url) : false;
            Log::warning('Flutterwave checkout link rejected', [
                'payment_id' => $payment['id'] ?? null,
                'response_keys' => array_keys($data),
                'link_type' => get_debug_type($url),
                'scheme' => is_array($parts) ? ($parts['scheme'] ?? null) : null,
                'host' => is_array($parts) ? ($parts['host'] ?? null) : null,
                'contains_credentials' => is_array($parts) && (isset($parts['user']) || isset($parts['pass'])),
            ]);
            throw new BillingException('The payment provider did not return a valid checkout.', 503);
        }

        return $url;
    }

    public function verify(string $reference): array
    {
        $data = $this->client->verify($reference);

        return [
            'id' => isset($data['id']) ? 'flutterwave:'.$data['id'] : null,
            'reference' => $data['tx_ref'] ?? null,
            'status' => match ($data['status'] ?? '') {
                'successful' => 'success', 'failed' => 'failed',
                'cancelled', 'canceled' => 'abandoned', default => 'pending',
            },
            'amount' => $this->minorUnits($data['amount'] ?? null),
            'currency' => $data['currency'] ?? null,
            // v3 scopes transactions to the secret key's environment.
            'domain' => str_starts_with((string) config('payment.flutterwave.secret'), 'FLWSECK_TEST') ? 'test' : 'live',
            'customer' => ['email' => $data['customer']['email'] ?? null],
            'fees' => isset($data['app_fee']) ? $this->minorUnits($data['app_fee']) : null,
        ];
    }

    private function minorUnits(mixed $amount): int
    {
        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            throw new BillingException('The payment amount is missing.', 503);
        }
        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new BillingException('The payment amount is invalid.', 503);
            }
            $amount = number_format($amount, 2, '.', '');
        }

        return CreditMath::decimal($amount);
    }
}
