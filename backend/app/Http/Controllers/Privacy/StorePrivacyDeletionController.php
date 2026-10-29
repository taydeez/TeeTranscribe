<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Requests\Privacy\StorePrivacyDeletionRequest;
use App\Http\Responses\Privacy\PrivacyResponse;
use App\Infrastructure\Privacy\PrivacyDeletionDispatcher;
use Illuminate\Http\JsonResponse;

final class StorePrivacyDeletionController
{
    public function __invoke(StorePrivacyDeletionRequest $request, PrivacyService $privacy, PrivacyDeletionDispatcher $dispatcher, PrivacyResponse $response): JsonResponse
    {
        $input = $request->validated();
        $record = $privacy->delete((int) $request->user()->getAuthIdentifier(), $input['resource_type'], $input['resource_id'],
            $input['scope'], $input['category'] ?? null);
        $dispatcher->dispatch($record);

        return $response->json($response->data($record), 202);
    }
}
