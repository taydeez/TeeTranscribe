<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class OperationalLogResponse extends ApiResponse
{
    public function listing(array $logs): JsonResponse
    {
        return $this->json($logs)->header('Cache-Control', 'private, no-store');
    }

    public function detail(?array $log): JsonResponse
    {
        abort_if($log === null, 404);

        return $this->json(['data' => $log])->header('Cache-Control', 'private, no-store');
    }
}
