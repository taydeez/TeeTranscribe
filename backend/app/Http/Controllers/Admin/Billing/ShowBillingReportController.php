<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Billing\Contracts\BillingConfigurationRepositoryInterface;
use App\Http\Requests\Admin\Billing\ShowBillingReportRequest;
use App\Http\Responses\Admin\BillingConfigurationResponse;
use Illuminate\Http\JsonResponse;

final class ShowBillingReportController
{
    public function __invoke(ShowBillingReportRequest $request, BillingConfigurationRepositoryInterface $repository, BillingConfigurationResponse $response): JsonResponse
    {
        return $response->record($repository->report(...$request->period()));
    }
}
