<?php

namespace App\Http\Responses\Admin;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class AIProviderConfigurationResponse extends ApiResponse
{
    public function record(array $data): JsonResponse
    {
        return $this->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
