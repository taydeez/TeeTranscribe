<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentMethodService;
use App\Http\Requests\Billing\ListPaymentMethodsRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class ListPaymentMethodsController
{
    public function __invoke(ListPaymentMethodsRequest $request, PaymentMethodService $methods, BillingResponse $response): JsonResponse
    {
        return $response->paymentMethods($methods->available($request->validated('currency', 'NGN')));
    }
}
