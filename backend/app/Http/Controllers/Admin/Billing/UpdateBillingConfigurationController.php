<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Billing\Services\BillingConfigurationService;
use App\Http\Requests\Admin\Billing\UpdateBillingConfigurationRequest;
use App\Http\Responses\Admin\BillingConfigurationResponse;
use Illuminate\Http\JsonResponse;

final class UpdateBillingConfigurationController
{
    public function __invoke(UpdateBillingConfigurationRequest $request, BillingConfigurationService $service, BillingConfigurationResponse $response): JsonResponse
    {
        return $response->record($service->update($request->validated(), (int) $request->user()->getAuthIdentifier()));
    }
}
