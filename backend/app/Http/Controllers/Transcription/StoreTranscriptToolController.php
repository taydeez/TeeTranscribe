<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\TranscriptToolService;
use App\Http\Requests\Transcription\StoreTranscriptToolRequest;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;

final class StoreTranscriptToolController
{
    public function __invoke(StoreTranscriptToolRequest $request, string $transcription, TranscriptToolService $service, TranscriptionResponse $response): JsonResponse
    {
        $input = $request->validated();
        $userId = (int) $request->user()->getAuthIdentifier();
        $record = $service->submit($transcription, $userId, $input['quote_id']);

        return $response->json($response->data($record, TranscriptToolService::fingerprint($service->source($transcription, $userId))), $record->status === 'complete' ? 200 : 202);
    }
}
