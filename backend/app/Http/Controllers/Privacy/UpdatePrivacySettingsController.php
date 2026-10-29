<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Requests\Privacy\UpdatePrivacySettingsRequest;
use App\Http\Responses\Privacy\PrivacyResponse;
use Illuminate\Http\JsonResponse;

final class UpdatePrivacySettingsController
{
    public function __invoke(UpdatePrivacySettingsRequest $request, PrivacyService $privacy, PrivacyResponse $response): JsonResponse
    {
        $input = $request->validated();
        $changes = array_map(fn ($hours): ?int => $hours === null ? null : (int) $hours, $input['retention']);

        return $response->json($privacy->updateSettings((int) $request->user()->getAuthIdentifier(), $changes));
    }
}
