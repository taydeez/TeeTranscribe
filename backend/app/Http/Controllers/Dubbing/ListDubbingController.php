<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Dubbing\Services\DubbingService;
use App\Http\Requests\Dubbing\ListDubbingRequest;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;

final class ListDubbingController
{
    public function __invoke(ListDubbingRequest $request, DubbingService $service, DubbingResponse $response): JsonResponse
    {
        return $response->history($service->history($request->user()->id, (int) $request->validated('page', 1), (int) $request->validated('per_page', 10)));
    }
}
