<?php

namespace App\Http\Controllers\Admin\Log;

use App\Domain\Admin\Log\Services\OperationalLogService;
use App\Http\Requests\Admin\Log\ListOperationalLogRequest;
use App\Http\Responses\Admin\OperationalLogResponse;
use Illuminate\Http\JsonResponse;

final class ListOperationalLogController
{
    public function __invoke(ListOperationalLogRequest $request, OperationalLogService $service, OperationalLogResponse $response): JsonResponse
    {
        return $response->listing($service->paginate($request->validated()));
    }
}
