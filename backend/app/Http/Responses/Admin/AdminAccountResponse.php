<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AdminAccountResponse extends ApiResponse
{
    public function record(array $data, int $status = 200): JsonResponse
    {
        return $this->json(['data' => $data], $status)->header('Cache-Control', 'private, no-store');
    }

    public function changedPassword(): JsonResponse
    {
        return $this->json(['message' => 'Password updated. Please sign in again.']);
    }
}
