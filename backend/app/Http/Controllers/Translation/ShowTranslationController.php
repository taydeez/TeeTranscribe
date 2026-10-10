<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Translation\Services\TranslationService;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowTranslationController
{
    public function __invoke(Request $request, string $translation, TranslationService $service, TranslationResponse $response): JsonResponse
    {
        return $response->json($response->data($service->find($translation, (int) $request->user()->getAuthIdentifier())));
    }
}
