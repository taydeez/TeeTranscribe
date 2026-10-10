<?php

namespace App\Http\Controllers\Admin\AIProvider;

use App\Domain\Admin\AIProvider\Services\AIProviderConfigurationService;
use App\Http\Requests\Admin\AIProvider\UpdateAIProviderConfigurationRequest;
use App\Http\Responses\Admin\AIProviderConfigurationResponse;
use Illuminate\Http\JsonResponse;

final class UpdateAIProviderConfigurationController
{
    public function __invoke(UpdateAIProviderConfigurationRequest $request, string $activity, AIProviderConfigurationService $service, AIProviderConfigurationResponse $response): JsonResponse
    {
        return $response->record($service->update($activity, $request->validated(), (int) $request->user()->getAuthIdentifier()));
    }
}
