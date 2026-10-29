<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Requests\Privacy\ListPrivacyFilesRequest;
use App\Http\Responses\Privacy\PrivacyResponse;
use Illuminate\Http\JsonResponse;

final class ListPrivacyFilesController
{
    public function __invoke(ListPrivacyFilesRequest $request, PrivacyService $privacy, PrivacyResponse $response): JsonResponse
    {
        $input = $request->validated();

        return $response->json($privacy->files((int) $request->user()->getAuthIdentifier(), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20)));
    }
}
