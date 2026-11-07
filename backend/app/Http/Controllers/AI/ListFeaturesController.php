<?php

namespace App\Http\Controllers\AI;

use App\Domain\AI\Services\FeatureAvailability;
use App\Http\Responses\AI\FeatureResponse;
use Illuminate\Http\JsonResponse;

final class ListFeaturesController
{
    public function __invoke(FeatureAvailability $features, FeatureResponse $response): JsonResponse
    {
        return $response->catalog($features->all());
    }
}
