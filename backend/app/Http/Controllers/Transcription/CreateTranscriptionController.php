<?php

/*
 * © 2026 Demilade Oyewusi
 * Licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Transcription;

use App\Domain\Folder\Services\FolderService;
use App\Domain\Transcriber\Services\TranscribeService;
use App\Http\Requests\Transcription\TranscribeAudioRequest;
use Illuminate\Http\JsonResponse;

class CreateTranscriptionController
{
    public function __construct(
        private readonly TranscribeService $transcribeService,
        private readonly FolderService $folderService,
    ) {}

    public function __invoke(TranscribeAudioRequest $request): JsonResponse
    {

        $data = $request->validated();
        $authenticatedUser = $request->user('sanctum');
        $folderId = $data['folder_id'] ?? null;
        $folder = null;
        unset($data['folder_id']);

        if ($authenticatedUser !== null) {
            $userId = (int) $authenticatedUser->getKey();
            $data['user_id'] = $userId;
            unset($data['guest_session_id']);

            $folder = is_string($folderId)
                ? $this->folderService->findOrFail($folderId, $userId)
                : $this->folderService->findOrCreateByName($userId, now()->format('F j, Y'));
        } else {
            unset($data['user_id']);
        }

        $transcription = $this->transcribeService->startNewTranscription($data);

        if ($folder !== null) {
            $this->folderService->attachTranscription($folder->id, $userId, $transcription->id);
        }

        return response()->json('file has been sent for transcription');

    }
}
