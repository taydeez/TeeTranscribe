<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Billing\Services\PaidTranscriptionService;
use App\Http\Requests\Transcription\TranscribeAudioRequest;
use Illuminate\Http\JsonResponse;

class CreateTranscriptionController
{
    public function __invoke(TranscribeAudioRequest $request, PaidTranscriptionService $service): JsonResponse
    {
        $data = $request->validated();
        $transcription = $service->submit((int) $request->user()->getAuthIdentifier(), $data['quote_id'], $data['folder_id'] ?? null, $data['name'] ?? null);

        return response()->json(['id' => $transcription->id, 'status' => $transcription->status, 'message' => 'File queued for transcription.'], 202);
    }
}
