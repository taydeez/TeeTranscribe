<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\Entities\PaymentMethod;

interface PaymentMethodRepositoryInterface
{
    /** @return list<PaymentMethod> */
    public function active(): array;

    public function findActiveByCode(string $code): ?PaymentMethod;
}
