<?php

namespace App\Infrastructure\Payment\Gateways;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use App\Infrastructure\Payment\Bachs\BachsClient;

final readonly class BachsPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(private BachsClient $client, private PaymentRepositoryInterface $payments) {}

    public function code(): string
    {
        return 'bachs';
    }

    public function webhookIsValid(string $payload, string $signature): bool
    {
        $secret = (string) config('payment.bachs.webhook_secret');
        $timestamps = [];
        $signatures = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamps[] = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($secret === '' || count($timestamps) !== 1 || ! ctype_digit($timestamps[0])
            || abs(time() - (int) $timestamps[0]) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamps[0].'.'.$payload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function referenceFromWebhook(array $payload): ?string
    {
        if (! in_array($payload['type'] ?? null, ['collection.succeeded', 'collection.failed', 'collection.underpaid', 'checkout.completed', 'checkout.expired'], true)) {
            return null;
        }
        $id = $payload['data']['checkout_id'] ?? null;
        if (! is_string($id)) {
            return null;
        }
        $checkout = $this->client->checkout($id);

        return is_string($checkout['reference'] ?? null) ? $checkout['reference'] : null;
    }

    public function initialize(array $payment, string $email): string
    {
        if ($payment['currency'] !== 'USD') {
            throw new BillingException('Bachs currently supports USD purchases in this application.', 422);
        }
        if (! empty($payment['provider_checkout_id'])) {
            $data = $this->client->checkout($payment['provider_checkout_id']);
        } else {
            $amount = (int) $payment['amount_minor'];
            $callback = (string) config('payment.bachs.callback_url');
            $callback .= (str_contains($callback, '?') ? '&' : '?').'reference='.rawurlencode($payment['reference']);
            $data = $this->client->initialize([
                'pricing' => ['currency' => 'USD', 'amount' => intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT)],
                'customer' => ['email' => $email], 'reference' => $payment['reference'],
                'success_url' => $callback, 'cancel_url' => $callback,
                'payment_method_types' => ['USD_CARD'],
                'metadata' => ['payment_id' => $payment['id']],
            ], $payment['reference']);
        }
        $url = $data['checkout_url'] ?? null;
        $host = str_starts_with((string) config('payment.bachs.secret'), 'sk_sandbox_') ? 'sandbox-checkout.bachs.io' : 'checkout.bachs.io';
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_HOST) !== $host || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
            || ! preg_match('/\Achk_[a-zA-Z0-9_-]{1,128}\z/', $data['checkout_id'])) {
            throw new BillingException('The payment provider did not return a valid checkout.', 503);
        }
        $this->payments->updatePayment($payment['id'], ['provider_checkout_id' => $data['checkout_id']]);

        return $url;
    }

    public function verify(string $reference): array
    {
        $payment = $this->payments->paymentByReference($reference);
        if ($payment === null || $payment['gateway'] !== 'bachs' || empty($payment['provider_checkout_id'])) {
            throw new BillingException('The Bachs checkout is not available for verification yet.', 503);
        }
        $data = $this->client->checkout($payment['provider_checkout_id']);
        if ($data['checkout_id'] !== $payment['provider_checkout_id']) {
            throw new BillingException('The payment checkout does not match this purchase.', 409);
        }
        $charge = $data['charge'] ?? [];
        $success = strtolower($data['status'] ?? '') === 'completed'
            && strtolower($data['payment_status'] ?? '') === 'succeeded'
            && strtolower($charge['status'] ?? '') === 'succeeded';
        if ($success && (($charge['currency'] ?? null) !== ($data['currency'] ?? null)
            || $this->minorUnits($charge['amount'] ?? null) !== $this->minorUnits($data['amount'] ?? null))) {
            throw new BillingException('The amount collected does not match the checkout.', 409);
        }
        $transactionId = $charge['payment_id'] ?? null;

        return ['id' => is_string($transactionId) && preg_match('/\A(?:ch|pay)_[a-zA-Z0-9_-]{1,128}\z/', $transactionId) ? 'bachs:'.$transactionId : null,
            'reference' => $data['reference'] ?? null,
            'status' => $success ? 'success' : (strtolower($data['status'] ?? '') === 'expired' ? 'abandoned' : 'pending'),
            'amount' => $this->minorUnits($data['amount'] ?? null), 'currency' => $data['currency'] ?? null,
            'domain' => str_starts_with((string) config('payment.bachs.secret'), 'sk_sandbox_') ? 'test' : 'live',
            'fees' => ($data['currency'] ?? null) === 'USD' && isset($charge['fee_usd']) ? $this->minorUnits($charge['fee_usd']) : null];
    }

    private function minorUnits(mixed $amount): int
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw new BillingException('The payment amount is invalid.', 503);
        }

        return CreditMath::decimal($amount);
    }
}
