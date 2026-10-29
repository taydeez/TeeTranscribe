<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Billing\Services\BillingConfigurationService;
use App\Http\Responses\Admin\BillingConfigurationResponse;
use Illuminate\Http\JsonResponse;

final class ShowBillingConfigurationController
{
    public function __invoke(BillingConfigurationService $service, BillingConfigurationResponse $response): JsonResponse
    {
        return $response->record($service->record());
    }
}
