<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Translation\Services\TranslationService;
use App\Http\Requests\Translation\StoreTranslationRequest;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;

final class StoreTranslationController
{
    public function __invoke(StoreTranslationRequest $request, TranslationService $service, TranslationResponse $response): JsonResponse
    {
        return $response->store($service->submit((int) $request->user()->getAuthIdentifier(), $request->validated('quote_id')));
    }
}
