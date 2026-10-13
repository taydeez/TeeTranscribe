<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DashboardResponse extends ApiResponse
{
    public function report(array $data): JsonResponse
    {
        return $this->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
