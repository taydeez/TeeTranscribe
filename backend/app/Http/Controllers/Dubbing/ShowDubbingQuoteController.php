<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowDubbingQuoteController
{
    public function __invoke(Request $request, string $quote, BillingRepositoryInterface $repository, CreditService $credits, DubbingResponse $response): JsonResponse
    {
        $record = $repository->quote($quote, $request->user()->id) ?? throw new BillingException('Quote not found.', 404);
        if ($record['activity'] !== 'dubbing') {
            throw new BillingException('Quote not found.', 404);
        }

        return $response->json($response->quoteData($record, $credits));
    }
}
