<?php

namespace App\Http\Controllers\Folder;

use App\Domain\Folder\Services\FolderService;
use App\Http\Responses\Folder\FolderResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DetachFolderTranscriptionController
{
    public function __invoke(Request $request, string $folder, string $transcription, FolderService $service, FolderResponse $response): JsonResponse
    {
        return $response->json($response->toResponse($service->detachTranscription(
            $folder,
            (int) $request->user()->getAuthIdentifier(),
            $transcription,
        )));
    }
}
