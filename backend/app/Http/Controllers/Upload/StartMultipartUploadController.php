<?php

namespace App\Http\Controllers\Upload;

use App\Domain\Upload\Services\MultipartUploadService;
use App\Http\Requests\Upload\StartMultipartUploadRequest;
use App\Http\Responses\Upload\UploadResponse;
use Illuminate\Http\JsonResponse;

final class StartMultipartUploadController
{
    public function __invoke(StartMultipartUploadRequest $request, MultipartUploadService $service, UploadResponse $response): JsonResponse
    {
        $data = $request->validated();
        $data['size'] = (int) $data['size'];
        $session = $service->start((int) $request->user()->getAuthIdentifier(), $data);

        return $response->json($response->representation($session));
    }
}
