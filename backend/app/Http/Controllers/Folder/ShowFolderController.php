<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowFolderController
{
    public function __invoke(Request $request, string $folder, FolderService $service, FolderResponse $response): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        return $response->detail($service->findOrFail($folder, $userId), $service->transcriptionsForUser($folder, $userId), $service->projectsForUser($folder, $userId));
    }
}
