<?php

namespace App\Http\Responses\AI;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class FeatureResponse extends ApiResponse
{
    public function catalog(array $features): JsonResponse
    {
        return $this->json(['data' => $features])->header('Cache-Control', 'no-store');
    }
}
