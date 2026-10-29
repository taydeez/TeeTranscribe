<?php

namespace App\Http\Responses\Payment;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PaymentResponse extends ApiResponse
{
    public function received(): JsonResponse
    {
        return $this->json(['received' => true]);
    }
}
