<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentService;
use App\Http\Requests\Billing\VerifyPaymentRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class VerifyPaymentController
{
    public function __invoke(VerifyPaymentRequest $request, PaymentService $payments, BillingResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json($response->paymentData($payments->verify($data['reference'], (int) $request->user()->getAuthIdentifier())));
    }
}
