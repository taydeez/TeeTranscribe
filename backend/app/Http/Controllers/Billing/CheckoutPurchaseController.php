<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentService;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutPurchaseController
{
    public function __invoke(Request $request, string $payment, PaymentService $payments, BillingResponse $response): JsonResponse
    {
        return $response->json($response->paymentData($payments->checkout($payment, (int) $request->user()->getAuthIdentifier())));
    }
}
