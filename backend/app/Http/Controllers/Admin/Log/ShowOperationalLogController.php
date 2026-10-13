<?php

namespace App\Http\Controllers\Admin\Log;

use App\Domain\Admin\Log\Services\OperationalLogService;
use App\Http\Responses\Admin\OperationalLogResponse;
use Illuminate\Http\JsonResponse;

final class ShowOperationalLogController
{
    public function __invoke(string $log, OperationalLogService $service, OperationalLogResponse $response): JsonResponse
    {
        return $response->detail($service->find($log));
    }
}
