<?php

namespace App\Domain\Payment\Services;

use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditMath;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Payment\Contracts\PaymentGatewayResolverInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use DateTimeImmutable;

final class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $repository,
        private readonly BillingSettingsInterface $settings,
        private readonly PaymentGatewayResolverInterface $gateways,
        private readonly CreditService $credits,
        private readonly PaymentMethodService $methods,
    ) {}

    public function packagePrices(string $currency): array
    {
        if (! in_array($currency, ['NGN', 'USD'], true)) {
            throw new BillingException('Choose NGN or USD.', 422);
        }
        $fx = $currency === 'USD' ? $this->settings->fx() : null;
        $packages = [];
        foreach ($this->settings->packages() as $key => $package) {
            $units = CreditMath::decimal($package['credits']);
            $amount = $currency === 'NGN' ? $units : CreditMath::usdCents($units, $fx['ngn_per_usd_micros']);
            $packages[] = [
                'id' => $key, 'name' => $package['name'], 'credit_units' => $units,
                'amount_minor' => $amount, 'currency' => $currency,
                'fx_ngn_per_usd_micros' => $fx['ngn_per_usd_micros'] ?? null,
            ];
        }

        return $packages;
    }

    public function quote(int $userId, string $email, string $packageId, string $currency, string $key, string $paymentMethod): array
    {
        return $this->repository->exclusive('purchase:'.$userId.':'.$key, function () use ($userId, $email, $packageId, $currency, $key, $paymentMethod): array {
            $existing = $this->repository->paymentByClientKey($userId, $key);
            if ($existing !== null) {
                if ($existing['package_key'] !== $packageId || $existing['currency'] !== $currency || $existing['gateway'] !== $paymentMethod) {
                    throw new BillingException('This purchase reference belongs to another package, currency, or payment method.');
                }

                return $existing;
            }
            $method = $this->methods->requireAvailable($paymentMethod, $currency);
            $package = null;
            foreach ($this->packagePrices($currency) as $item) {
                if ($item['id'] === $packageId) {
                    $package = $item;
                }
            }
            if ($package === null) {
                throw new BillingException('Credit package not found.', 404);
            }
            if ($package['amount_minor'] < ($currency === 'USD' ? 200 : 5000)) {
                throw new BillingException('This package is below the minimum checkout amount.', 422);
            }

            return $this->repository->createPayment([
                'user_id' => $userId, 'client_key' => $key, 'customer_email' => $email,
                'gateway' => $method->code, 'payment_method_id' => $method->id,
                'package_key' => $packageId, 'package_name' => $package['name'],
                'credit_units' => $package['credit_units'], 'amount_minor' => $package['amount_minor'],
                'currency' => $currency, 'fx_ngn_per_usd_micros' => $package['fx_ngn_per_usd_micros'],
                'expires_at' => $this->settings->checkoutExpiresAt(), 'status' => 'quoted',
            ]);
        });
    }

    public function checkout(string $id, int $userId): array
    {
        return $this->repository->exclusive('checkout:'.$id, function () use ($id, $userId): array {
            $payment = $this->repository->payment($id, $userId)
                ?? throw new BillingException('Payment not found.', 404);
            if ($payment['status'] === 'paid') {
                return $payment;
            }
            if ($payment['status'] === 'expired' || new DateTimeImmutable($payment['expires_at']) <= new DateTimeImmutable) {
                throw new BillingException('This checkout quote expired. Select the package again.');
            }
            if ($payment['checkout_url'] !== null) {
                return $payment;
            }
            $this->repository->updatePayment($id, ['status' => 'initializing']);
            $url = $this->gateways->resolve($payment['gateway'])->initialize($payment, $payment['customer_email']);

            return $this->repository->updatePayment($id, ['status' => 'pending', 'checkout_url' => $url]);
        });
    }

    public function handleWebhook(string $provider, string $payload, string $signature): void
    {
        $gateway = $this->gateways->resolve($provider);
        if (! $gateway->webhookIsValid($payload, $signature)) {
            throw new BillingException('Invalid payment webhook signature.', 403);
        }
        $data = json_decode($payload, true);
        if (! is_array($data)) {
            throw new BillingException('Invalid payment webhook payload.', 400);
        }
        $reference = $gateway->referenceFromWebhook($data);
        if ($reference !== null) {
            $this->verify($reference, provider: $provider);
        }
    }

    public function verify(string $reference, ?int $userId = null, ?string $provider = null): ?array
    {
        $payment = $this->repository->paymentByReference($reference);
        if ($payment === null || ($userId !== null && (int) $payment['user_id'] !== $userId)) {
            if ($userId !== null) {
                throw new BillingException('Payment not found.', 404);
            }

            return null;
        }
        if ($provider !== null && $payment['gateway'] !== $provider) {
            throw new BillingException('The webhook provider does not match this payment.');
        }
        if ($payment['status'] === 'paid') {
            return $payment;
        }
        $verified = $this->gateways->resolve($payment['gateway'])->verify($reference);
        $mismatches = array_keys(array_filter([
            'reference' => ($verified['reference'] ?? null) !== $reference,
            'amount' => (int) ($verified['amount'] ?? -1) !== (int) $payment['amount_minor'],
            'currency' => ($verified['currency'] ?? null) !== $payment['currency'],
            'environment' => ($verified['domain'] ?? null) !== $payment['environment'],
        ]));
        if ($mismatches !== []) {
            throw new BillingException('The payment details do not match this purchase. Mismatched fields: '.implode(', ', $mismatches).'.');
        }
        if (($verified['status'] ?? null) !== 'success') {
            if (in_array($verified['status'] ?? '', ['failed', 'abandoned', 'reversed'], true)) {
                return $this->repository->updatePayment($payment['id'], ['status' => 'failed']);
            }

            return $payment;
        }
        if (! isset($verified['id']) || (string) $verified['id'] === '') {
            throw new BillingException('The payment transaction ID is missing.');
        }

        return $this->repository->transaction(function () use ($payment, $verified): array {
            $locked = $this->repository->payment($payment['id'], lock: true);
            if ($locked['status'] === 'paid') {
                return $locked;
            }
            $this->credits->purchase((int) $locked['user_id'], (int) $locked['credit_units'], $locked['reference']);

            $paid = $this->repository->updatePayment($locked['id'], [
                'status' => 'paid', 'paid_at' => (new DateTimeImmutable)->format(DATE_ATOM),
                'provider_transaction_id' => (string) ($verified['id'] ?? ''),
                'provider_fee_minor' => isset($verified['fees']) ? (int) $verified['fees'] : null,
            ]);
            $this->repository->enqueueInvoice($locked['id']);

            return $paid;
        });
    }
}
