<?php

namespace App\Http\Controllers\Activity;

use App\Domain\Billing\Services\ActivityLifecycleService;
use App\Http\Requests\Activity\CancelActivityRequest;
use App\Http\Responses\Activity\ActivityResponse;
use Illuminate\Http\JsonResponse;

final class CancelActivityController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(CancelActivityRequest $request, string $type, string $activity, ActivityLifecycleService $service, ActivityResponse $response): JsonResponse
    {
        return $response->stopped($service->cancel($type, $activity, (int) $request->user()->getAuthIdentifier()));
    }
}
