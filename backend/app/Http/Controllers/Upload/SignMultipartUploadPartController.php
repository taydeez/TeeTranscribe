<?php

namespace App\Http\Controllers\Upload;

use App\Domain\Upload\Services\MultipartUploadService;
use App\Http\Requests\Upload\SignMultipartUploadPartRequest;
use App\Http\Responses\Upload\UploadResponse;
use Illuminate\Http\JsonResponse;

final class SignMultipartUploadPartController
{
    public function __invoke(SignMultipartUploadPartRequest $request, string $upload, MultipartUploadService $service, UploadResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json($service->signPart($upload, (int) $request->user()->getAuthIdentifier(), (int) $data['part_number']));
    }
}
