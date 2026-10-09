<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Requests\Folder\StoreFolderRequest;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\JsonResponse;

final class StoreFolderController
{
    public function __invoke(StoreFolderRequest $request, FolderService $service, FolderResponse $response): JsonResponse
    {
        $data = $request->validated();

        return $response->json(
            $response->toResponse($service->create((int) $request->user()->getAuthIdentifier(), $data['name'])),
            201,
        );
    }
}
