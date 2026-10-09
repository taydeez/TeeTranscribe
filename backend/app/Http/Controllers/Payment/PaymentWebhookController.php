<?php

namespace App\Http\Controllers\Payment;

use App\Domain\Payment\Services\PaymentService;
use App\Http\Responses\Payment\PaymentResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentWebhookController
{
    public function __invoke(Request $request, string $provider, PaymentService $payments, PaymentResponse $response): JsonResponse
    {
        $signature = (string) $request->header($provider === 'paystack' ? 'x-paystack-signature' : 'verif-hash');
        $payments->handleWebhook($provider, $request->getContent(), $signature);

        return $response->received();
    }
}
