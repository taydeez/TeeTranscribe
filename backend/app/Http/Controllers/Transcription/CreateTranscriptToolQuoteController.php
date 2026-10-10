<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Transcriber\Services\TranscriptToolService;
use App\Http\Requests\Transcription\CreateTranscriptToolQuoteRequest;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;

final class CreateTranscriptToolQuoteController
{
    public function __invoke(CreateTranscriptToolQuoteRequest $request, string $transcription, CreditService $credits, TranscriptToolService $service, TranscriptionResponse $response): JsonResponse
    {
        $input = $request->validated();
        $userId = (int) $request->user()->getAuthIdentifier();
        $quote = $service->quote($transcription, $userId, $input['operation'], $input['client_key']);
        $balance = $credits->balance($userId);

        return $response->json($response->quoteData($quote, $balance));
    }
}
