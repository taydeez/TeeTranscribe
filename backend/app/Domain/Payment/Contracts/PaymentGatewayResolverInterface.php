<?php

namespace App\Domain\Payment\Contracts;

interface PaymentGatewayResolverInterface
{
    public function resolve(string $provider): PaymentGatewayInterface;
}
