<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentService;
use App\Http\Requests\Billing\StorePurchaseRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class StorePurchaseController
{
    public function __invoke(StorePurchaseRequest $request, PaymentService $payments, BillingResponse $response): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $response->json($response->paymentData($payments->quote((int) $user->getAuthIdentifier(), $user->email, $data['package_id'], $data['currency'], $data['client_key'], $data['payment_method'])), 201);
    }
}
