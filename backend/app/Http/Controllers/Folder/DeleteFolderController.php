<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeleteFolderController
{
    public function __invoke(Request $request, string $folder, FolderService $service, FolderResponse $response): Response
    {
        $service->delete($folder, (int) $request->user()->getAuthIdentifier());

        return $response->noContent();
    }
}
