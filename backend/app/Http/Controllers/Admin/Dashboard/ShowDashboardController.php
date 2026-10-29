<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Domain\Admin\Dashboard\Services\DashboardService;
use App\Http\Requests\Admin\Dashboard\ShowDashboardRequest;
use App\Http\Responses\Admin\DashboardResponse;
use Illuminate\Http\JsonResponse;

final class ShowDashboardController
{
    public function __invoke(ShowDashboardRequest $request, DashboardService $service, DashboardResponse $response): JsonResponse
    {
        return $response->report($service->report(...$request->period()));
    }
}
