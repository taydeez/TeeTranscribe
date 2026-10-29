<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Requests\Folder\UpdateFolderRequest;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\JsonResponse;

final class UpdateFolderController
{
    public function __invoke(UpdateFolderRequest $request, string $folder, FolderService $service, FolderResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json($response->toResponse($service->update(
            $folder,
            (int) $request->user()->getAuthIdentifier(),
            $data['name'],
        )));
    }
}
