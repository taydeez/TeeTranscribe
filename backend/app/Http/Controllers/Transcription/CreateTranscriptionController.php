<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\TranscribeService;
use App\Http\Requests\Transcription\TranscribeAudioRequest;
use Illuminate\Http\JsonResponse;

class CreateTranscriptionController
{
    public function __construct(private readonly TranscribeService $transcribeService) {}

    public function __invoke(TranscribeAudioRequest $request): JsonResponse
    {

        $data = $request->validated();

        $this->transcribeService->startNewTranscription($data);

        return response()->json("file has been sent for transcription");

    }
}
