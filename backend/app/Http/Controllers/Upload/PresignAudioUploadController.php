<?php

namespace App\Http\Controllers\Upload;

use App\Http\Requests\Transcription\PresignAudioUploadRequest;
use App\Http\Responses\Upload\UploadResponse;
use App\Infrastructure\Upload\GateWays\R2Gateway;
use Illuminate\Http\JsonResponse;

final class PresignAudioUploadController
{
    public function __invoke(PresignAudioUploadRequest $request, R2Gateway $r2Gateway, UploadResponse $response): JsonResponse
    {
        return $response->json($r2Gateway->Presign($request->validated()));
    }
}
