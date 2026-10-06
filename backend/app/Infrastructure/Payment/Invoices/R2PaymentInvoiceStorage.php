<?php

namespace App\Infrastructure\Payment\Invoices;

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Payment\Contracts\PaymentInvoiceStorageInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class R2PaymentInvoiceStorage implements PaymentInvoiceStorageInterface
{
    public function create(array $payment): string
    {
        $path = 'invoices/'.$payment['user_id'].'/'.$payment['id'].'/'.$payment['invoice_number'].'.pdf';
        $pdf = Pdf::loadView('invoices.payment', ['payment' => $payment])->setPaper('a4')->output();
        $disk = Storage::disk('r2');
        if (! $disk->put($path, $pdf, ['ContentType' => 'application/pdf'])) {
            throw new RuntimeException('Invoice upload to R2 failed.');
        }
        if (! $disk->exists($path) || $disk->size($path) !== strlen($pdf)) {
            throw new RuntimeException('Invoice upload to R2 could not be verified.');
        }

        return $path;
    }

    public function read(string $path): string
    {
        $pdf = Storage::disk('r2')->get($path);
        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('The stored invoice is unavailable.');
        }

        return $pdf;
    }

    public function download(string $path, string $filename): array
    {
        $disk = Storage::disk('r2');
        if (! $disk->exists($path)) {
            throw new BillingException('The invoice file is temporarily unavailable.', 503);
        }
        $expires = now()->addMinutes(5);

        return ['url' => $disk->temporaryUrl($path, $expires, ['ResponseContentType' => 'application/pdf', 'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"']), 'expires_at' => $expires->toIso8601String()];
    }
}
