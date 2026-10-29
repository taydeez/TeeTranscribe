<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Dubbing\Services\DubbingCatalog;
use App\Http\Responses\Dubbing\DubbingResponse;
use Illuminate\Http\JsonResponse;

final class ListDubbingLanguagesController
{
    public function __invoke(DubbingCatalog $catalog, DubbingResponse $response): JsonResponse
    {
        return $response->json($catalog->all());
    }
}
