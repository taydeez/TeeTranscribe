<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Responses\Privacy\PrivacyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowPrivacyDeletionController
{
    public function __invoke(Request $request, string $deletion, PrivacyService $privacy, PrivacyResponse $response): JsonResponse
    {
        return $response->json($response->data($privacy->find($deletion, (int) $request->user()->getAuthIdentifier())));
    }
}
