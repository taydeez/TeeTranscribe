<?php

namespace App\Http\Controllers\Translation;

use App\Domain\Translation\Services\TranslationLanguages;
use App\Http\Responses\Translation\TranslationResponse;
use Illuminate\Http\JsonResponse;

final class ListTranslationLanguagesController
{
    public function __invoke(TranslationLanguages $languages, TranslationResponse $response): JsonResponse
    {
        return $response->languages($languages->all());
    }
}
