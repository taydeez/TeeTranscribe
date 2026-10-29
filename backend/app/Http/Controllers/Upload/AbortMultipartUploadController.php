<?php

namespace App\Http\Controllers\Upload;

use App\Domain\Upload\Services\MultipartUploadService;
use App\Http\Responses\Upload\UploadResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AbortMultipartUploadController
{
    public function __invoke(Request $request, string $upload, MultipartUploadService $service, UploadResponse $response): JsonResponse
    {
        $service->abort($upload, (int) $request->user()->getAuthIdentifier());

        return $response->aborted();
    }
}
