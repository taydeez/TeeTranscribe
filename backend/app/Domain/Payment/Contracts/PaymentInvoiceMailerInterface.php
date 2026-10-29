<?php

namespace App\Domain\Payment\Contracts;

interface PaymentInvoiceMailerInterface
{
    public function send(array $payment, string $pdf): void;
}
