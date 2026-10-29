<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Translation\Services\TranslationService;
use App\Http\Requests\Translation\UpdateTranslationRequest;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;

final class UpdateTranslationController
{
    public function __invoke(UpdateTranslationRequest $request, string $translation, TranslationService $service, TranslationResponse $response): JsonResponse
    {
        $input = $request->validated();

        return $response->json($response->data($service->edit($translation, (int) $request->user()->getAuthIdentifier(), $input['translated_text'], $input['segments'] ?? null)));
    }
}
