<?php

namespace App\Domain\Payment\Services;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentInvoiceMailerInterface;
use App\Domain\Payment\Contracts\PaymentInvoiceStorageInterface;
use App\Domain\Payment\Contracts\PaymentRepositoryInterface;
use DateTimeImmutable;

final class PaymentInvoiceService
{
    public function __construct(private readonly PaymentRepositoryInterface $repository, private readonly PaymentInvoiceStorageInterface $storage, private readonly PaymentInvoiceMailerInterface $mailer) {}

    public function generate(string $paymentId): void
    {
        $this->repository->exclusive('invoice:'.$paymentId, function () use ($paymentId): void {
            $payment = $this->repository->payment($paymentId) ?? throw new BillingException('Payment not found.', 404);
            if ($payment['status'] !== 'paid') {
                throw new BillingException('Only confirmed payments have an invoice.', 409);
            }
            if ($payment['invoice_storage_path'] === null) {
                $payment['invoice_number'] = 'INV-'.strtoupper($payment['id']);
                $path = $this->storage->create($payment);
                $payment = $this->repository->updatePayment($paymentId, ['invoice_number' => $payment['invoice_number'], 'invoice_storage_path' => $path]);
            }
            if ($payment['invoice_notified_at'] !== null) {
                return;
            }
            $this->mailer->send($payment, $this->storage->read($payment['invoice_storage_path']));
            $this->repository->updatePayment($paymentId, ['invoice_notified_at' => (new DateTimeImmutable)->format(DATE_ATOM)]);
        });
    }

    public function download(string $paymentId, int $userId): array
    {
        $payment = $this->repository->payment($paymentId, $userId) ?? throw new BillingException('Payment not found.', 404);
        if ($payment['status'] !== 'paid' || $payment['invoice_storage_path'] === null) {
            throw new BillingException('Your invoice is still being prepared. Please try again shortly.', 409);
        }

        return $this->storage->download($payment['invoice_storage_path'], $payment['invoice_number'].'.pdf');
    }
}
