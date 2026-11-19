<?php

namespace App\Http\Responses\Billing;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PublicPricingResponse extends ApiResponse
{
    public function catalog(array $data): JsonResponse
    {
        return $this->json(['data' => $data])->header('Cache-Control', 'public, max-age=60');
    }
}
