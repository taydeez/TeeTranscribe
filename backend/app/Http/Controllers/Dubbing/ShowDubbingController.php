<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Dubbing\Services\DubbingService;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowDubbingController
{
    public function __invoke(Request $request, string $dubbing, DubbingService $service, DubbingResponse $response): JsonResponse
    {
        return $response->show($service->find($dubbing, $request->user()->id));
    }
}
