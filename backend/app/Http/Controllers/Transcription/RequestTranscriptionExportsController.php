<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\RequestTranscriptionExports;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RequestTranscriptionExportsController
{
    public function __invoke(Request $request, string $transcription, RequestTranscriptionExports $service, TranscriptionResponse $response): JsonResponse
    {

        $record = $service->handle($transcription, (int) $request->user()->getAuthIdentifier());

        return $response->exports($record);
    }
}
