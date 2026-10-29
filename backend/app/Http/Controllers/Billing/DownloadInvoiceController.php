<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentInvoiceService;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DownloadInvoiceController
{
    public function __invoke(Request $request, string $payment, PaymentInvoiceService $invoices, BillingResponse $response): JsonResponse
    {
        return $response->invoice($invoices->download($payment, (int) $request->user()->getAuthIdentifier()));
    }
}
