<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Services\UsageQuoteService;
use App\Http\Requests\Billing\CreateUsageQuoteRequest;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;

final class CreateUsageQuoteController
{
    public function __invoke(CreateUsageQuoteRequest $request, UsageQuoteService $quotes, BillingResponse $response): JsonResponse
    {
        $data = $request->validated();
        $key = $data['client_key'];
        unset($data['client_key']);
        $quote = $quotes->create((int) $request->user()->getAuthIdentifier(), $data, $key);

        return $response->json($response->quoteData($quote), 202);
    }
}
