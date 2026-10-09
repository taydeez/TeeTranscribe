<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Services\DubbingService;
use App\Http\Requests\Dubbing\CreateDubbingQuoteRequest;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;

final class CreateDubbingQuoteController
{
    public function __invoke(CreateDubbingQuoteRequest $request, CreditService $credits, DubbingService $service, DubbingResponse $response): JsonResponse
    {
        $input = $request->validated();
        $key = $input['client_key'];
        unset($input['client_key']);
        $quote = $service->quote($request->user()->id, $input, $key);

        return $response->json($response->quoteData($quote, $credits), 202);
    }
}
