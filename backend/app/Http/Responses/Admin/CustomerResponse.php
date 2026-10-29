<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class CustomerResponse extends ApiResponse
{
    public function listing(array $customers): JsonResponse
    {
        return $this->json($customers)->header('Cache-Control', 'private, no-store');
    }
}
