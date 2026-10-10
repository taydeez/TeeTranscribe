<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\TranscriptToolService;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListTranscriptToolsController
{
    public function __invoke(Request $request, string $transcription, TranscriptToolService $service, TranscriptionResponse $response): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $history = $service->history($transcription, $userId);
        $fingerprint = TranscriptToolService::fingerprint($service->source($transcription, $userId));

        return $response->history($service->configured(), $history, $fingerprint);
    }
}
