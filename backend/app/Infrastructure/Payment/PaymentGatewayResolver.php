<?php

namespace App\Infrastructure\Payment;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\Contracts\PaymentGatewayResolverInterface;
use App\Infrastructure\Payment\Gateways\FlutterwavePaymentGateway;
use App\Infrastructure\Payment\Gateways\PaystackPaymentGateway;

final class PaymentGatewayResolver implements PaymentGatewayResolverInterface
{
    public function __construct(
        private readonly PaystackPaymentGateway $paystack,
        private readonly FlutterwavePaymentGateway $flutterwave,
    ) {}

    public function resolve(string $provider): PaymentGatewayInterface
    {
        return match ($provider) {
            'paystack' => $this->paystack,
            'flutterwave' => $this->flutterwave,
            default => throw new BillingException('The payment gateway is not supported.', 503),
        };
    }
}
