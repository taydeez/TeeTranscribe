<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Requests\Folder\ListFolderRequest;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\JsonResponse;

final class ListFolderController
{
    public function __invoke(ListFolderRequest $request, FolderService $service, FolderResponse $response): JsonResponse
    {
        $data = $request->validated();
        $page = $service->paginateForUser(
            userId: (int) $request->user()->getAuthIdentifier(),
            search: trim((string) ($data['search'] ?? '')),
            sort: $data['sort'] ?? 'created_at',
            direction: $data['direction'] ?? 'desc',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 10),
        );

        return $response->json($response->toPageResponse($page));
    }
}
