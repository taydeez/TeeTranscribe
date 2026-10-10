<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Billing\Services\PaidTranscriptionService;
use App\Http\Requests\Transcription\TranscribeAudioRequest;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;

final class CreateTranscriptionController
{
    public function __invoke(TranscribeAudioRequest $request, PaidTranscriptionService $service, TranscriptionResponse $response): JsonResponse
    {

        $data = $request->validated();
        $transcription = $service->submit((int) $request->user()->getAuthIdentifier(), $data['quote_id'], $data['folder_id'] ?? null, $data['name'] ?? null);

        return $response->created($transcription);
    }
}
