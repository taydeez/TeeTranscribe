<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Services\TranslationService;
use App\Http\Requests\Translation\CreateTranslationQuoteRequest;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;

final class CreateTranslationQuoteController
{
    public function __invoke(CreateTranslationQuoteRequest $request, CreditService $credits, TranslationService $service, TranslationResponse $response): JsonResponse
    {
        $input = $request->validated();
        $id = (int) $request->user()->getAuthIdentifier();
        $quote = $service->quote($id, $input, $input['client_key']);
        $balance = $credits->balance($id);

        return $response->json($response->quoteData($quote, $balance));
    }
}
