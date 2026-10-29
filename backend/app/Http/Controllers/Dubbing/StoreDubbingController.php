<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Dubbing\Services\DubbingService;
use App\Http\Requests\Dubbing\StoreDubbingRequest;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;

final class StoreDubbingController
{
    public function __invoke(StoreDubbingRequest $request, DubbingService $service, DubbingResponse $response): JsonResponse
    {
        return $response->store($service->submit($request->user()->id, $request->validated('quote_id')));
    }
}
