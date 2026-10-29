<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetryDubbingController
{
    public function __invoke(Request $request, string $dubbing, ProcessDubbing $processor, DubbingService $service, DubbingResponse $response): JsonResponse
    {
        $processor->retryExports($dubbing, $request->user()->id);

        return $response->show($service->find($dubbing, $request->user()->id));
    }
}
