<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Services\CreditService;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowBalanceController
{
    public function __invoke(Request $request, CreditService $credits, BillingResponse $response): JsonResponse
    {
        return $response->json($credits->balance((int) $request->user()->getAuthIdentifier()));
    }
}
