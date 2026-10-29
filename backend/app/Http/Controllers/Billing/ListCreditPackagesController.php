<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Payment\Services\PaymentMethodService;
use App\Domain\Payment\Services\PaymentService;
use App\Http\Requests\Billing\ListCreditPackagesRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class ListCreditPackagesController
{
    public function __invoke(ListCreditPackagesRequest $request, PaymentService $payments, PaymentMethodService $methods, BillingResponse $response): JsonResponse
    {
        $currency = $request->validated('currency', 'NGN');

        return $response->packages($payments->packagePrices($currency), $methods->available($currency), $methods->available('USD') !== []);
    }
}
