<?php

namespace App\Http\Responses\Upload;

use App\Domain\Upload\Entities\UploadSession;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class UploadResponse extends ApiResponse
{
    public function representation(UploadSession $session): array
    {
        return [
            'id' => $session->id, 'status' => $session->status->value,
            'filename' => $session->filename, 'size' => $session->size,
            'part_size' => $session->partSize, 'part_count' => $session->partCount(),
            'expires_at' => $session->expiresAt->format(DATE_ATOM),
        ];
    }

    public function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }

    public function aborted(): JsonResponse
    {
        return $this->json(['status' => 'aborted']);
    }

    public function inspected(UploadSession $session, array $parts): JsonResponse
    {
        return $this->json($this->representation($session) + ['parts' => $parts]);
    }
}
