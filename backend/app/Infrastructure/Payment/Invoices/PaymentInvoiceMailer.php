<?php

namespace App\Infrastructure\Payment\Invoices;

use App\Domain\Payment\Contracts\PaymentInvoiceMailerInterface;
use App\Mail\PaymentInvoiceMail;
use Illuminate\Support\Facades\Mail;

final class PaymentInvoiceMailer implements PaymentInvoiceMailerInterface
{
    public function send(array $payment, string $pdf): void
    {
        Mail::to($payment['customer_email'])->send(new PaymentInvoiceMail($payment, $pdf));
    }
}
