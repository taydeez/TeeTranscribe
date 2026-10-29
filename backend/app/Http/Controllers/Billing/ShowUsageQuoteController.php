<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Http\Responses\Billing\BillingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowUsageQuoteController
{
    public function __invoke(Request $request, string $quote, BillingRepositoryInterface $repository, BillingResponse $response): JsonResponse
    {
        $record = $repository->quote($quote, (int) $request->user()->getAuthIdentifier()) ?? throw new BillingException('Quote not found.', 404);

        return $response->json($response->quoteData($record));
    }
}
