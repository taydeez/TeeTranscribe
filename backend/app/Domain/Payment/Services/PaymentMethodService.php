<?php

namespace App\Domain\Payment\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentMethodRepositoryInterface;
use App\Domain\Payment\Contracts\PaymentSettingsInterface;
use App\Domain\Payment\Entities\PaymentMethod;

final class PaymentMethodService
{
    public function __construct(private readonly PaymentMethodRepositoryInterface $repository, private readonly PaymentSettingsInterface $settings) {}

    /** @return list<PaymentMethod> */
    public function available(string $currency): array
    {
        return array_values(array_filter($this->repository->active(), fn (PaymentMethod $method): bool => $this->supports($method, $currency)));
    }

    public function requireAvailable(string $code, string $currency): PaymentMethod
    {
        $method = $this->repository->findActiveByCode($code);
        if ($method === null || ! $this->supports($method, $currency)) {
            throw new BillingException('This payment method is unavailable for the selected currency.', 422);
        }

        return $method;
    }

    private function supports(PaymentMethod $method, string $currency): bool
    {
        return in_array($currency, $method->publicConfig['currencies'] ?? ['NGN'], true) && $this->settings->supports($method->code, $currency);
    }
}
