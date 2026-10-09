<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\TranscriptToolService;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowTranscriptToolController
{
    public function __invoke(Request $request, string $transcription, string $tool, TranscriptToolService $service, TranscriptionResponse $response): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();
        $record = $service->find($tool, $transcription, $userId);

        return $response->json($response->data($record, TranscriptToolService::fingerprint($service->source($transcription, $userId))));
    }
}
