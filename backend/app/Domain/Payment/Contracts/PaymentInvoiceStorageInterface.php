<?php

namespace App\Domain\Payment\Contracts;

interface PaymentInvoiceStorageInterface
{
    public function create(array $payment): string;

    public function read(string $path): string;

    /** @return array{url: string, expires_at: string} */
    public function download(string $path, string $filename): array;
}
