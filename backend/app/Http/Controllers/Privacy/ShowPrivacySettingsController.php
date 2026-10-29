<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Responses\Privacy\PrivacyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowPrivacySettingsController
{
    public function __invoke(Request $request, PrivacyService $privacy, PrivacyResponse $response): JsonResponse
    {
        return $response->json($privacy->settings((int) $request->user()->getAuthIdentifier()));
    }
}
