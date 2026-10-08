<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\RequestTranscriptionExports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestTranscriptionExportsController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, string $transcription, RequestTranscriptionExports $service): JsonResponse
    {
        $record = $service->handle($transcription, (int) $request->user()->getAuthIdentifier());

        return response()->json(['id' => $record->id, 'status' => $record->status], $record->status === 'processing' ? 202 : 200);
    }
}
