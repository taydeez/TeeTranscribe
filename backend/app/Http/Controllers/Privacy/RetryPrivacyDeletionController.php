<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\Services\PrivacyService;
use App\Http\Responses\Privacy\PrivacyResponse;
use App\Infrastructure\Privacy\PrivacyDeletionDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RetryPrivacyDeletionController
{
    public function __invoke(Request $request, string $deletion, PrivacyService $privacy, PrivacyDeletionDispatcher $dispatcher, PrivacyResponse $response): JsonResponse
    {
        $record = $privacy->retry($deletion, (int) $request->user()->getAuthIdentifier());
        $dispatcher->dispatch($record);

        return $response->json($response->data($record), 202);
    }
}
