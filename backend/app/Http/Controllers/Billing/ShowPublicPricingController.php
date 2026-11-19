<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Services\PublicPricingService;
use App\Http\Responses\Billing\PublicPricingResponse;
use Illuminate\Http\JsonResponse;

final class ShowPublicPricingController
{
    public function __invoke(PublicPricingService $pricing, PublicPricingResponse $response): JsonResponse
    {
        return $response->catalog($pricing->catalog());
    }
}
