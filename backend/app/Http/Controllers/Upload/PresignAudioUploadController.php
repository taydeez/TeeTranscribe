<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transcription\PresignAudioUploadRequest;
use Illuminate\Http\JsonResponse;
use App\Infrastructure\Upload\GateWays\R2Gateway;

class PresignAudioUploadController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(PresignAudioUploadRequest $request, R2Gateway $r2Gateway): JsonResponse
    {
        $data = $request->validated();

        $response = $r2Gateway->Presign($data);

        return response()->json($response)->header('Cache-Control', 'no-store');
    }
}
