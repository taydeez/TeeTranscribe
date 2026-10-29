<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Translation\Services\TranslationService;
use App\Http\Requests\Translation\ListTranslationRequest;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;

final class ListTranslationController
{
    public function __invoke(ListTranslationRequest $request, TranslationService $service, TranslationResponse $response): JsonResponse
    {
        return $response->history($service->history((int) $request->user()->getAuthIdentifier(), (int) $request->validated('page', 1), (int) $request->validated('per_page', 10)));
    }
}
