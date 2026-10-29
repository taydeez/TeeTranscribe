<?php

namespace App\Http\Controllers\Admin\AIProvider;

use App\Domain\Admin\AIProvider\Services\AIProviderConfigurationService;
use App\Http\Responses\Admin\AIProviderConfigurationResponse;
use Illuminate\Http\JsonResponse;

final class ListAIProviderConfigurationController
{
    public function __invoke(AIProviderConfigurationService $service, AIProviderConfigurationResponse $response): JsonResponse
    {
        return $response->record($service->all());
    }
}
