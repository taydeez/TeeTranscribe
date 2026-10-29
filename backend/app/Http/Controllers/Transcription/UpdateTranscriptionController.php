<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\EditTranscriptionService;
use App\Http\Requests\Transcription\UpdateTranscriptionRequest;
use App\Http\Responses\Transcription\TranscriptionResponse;
use Illuminate\Http\JsonResponse;

final class UpdateTranscriptionController
{
    public function __invoke(UpdateTranscriptionRequest $request, string $transcription, EditTranscriptionService $service, TranscriptionResponse $response): JsonResponse
    {

        $data = $request->validated();
        $updated = $service->edit(
            $transcription,
            (int) $request->user()->getAuthIdentifier(),
            $data['transcript'],
            $data['segments'] ?? null,
        );

        return $response->updated($updated);
    }
}
