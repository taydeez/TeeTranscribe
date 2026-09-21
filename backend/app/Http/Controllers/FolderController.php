<?php

namespace App\Http\Controllers;

use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderTranscription;
use App\Domain\Folder\Services\FolderService;
use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class FolderController extends Controller
{
    public function __construct(
        private readonly FolderService $service,
        private readonly TranscriptionExportUrlGeneratorInterface $exportUrlGenerator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['name', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $this->service->paginateForUser(
            userId: (int) $request->user()->getAuthIdentifier(),
            search: trim((string) ($data['search'] ?? '')),
            sort: $data['sort'] ?? 'created_at',
            direction: $data['direction'] ?? 'desc',
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 10),
        );

        return response()->json($this->toPageResponse($page));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return response()->json(
            $this->toResponse($this->service->create((int) $request->user()->getAuthIdentifier(), $data['name'])),
            201,
        );
    }

    public function show(Request $request, string $folder): JsonResponse
    {
        $userId = (int) $request->user()->getAuthIdentifier();

        return response()->json([
            ...$this->toResponse($this->service->findOrFail($folder, $userId)),
            'transcriptions' => array_map(
                $this->toTranscriptionResponse(...),
                $this->service->transcriptionsForUser($folder, $userId),
            ),
        ]);
    }

    public function update(Request $request, string $folder): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        return response()->json($this->toResponse($this->service->update(
            $folder,
            (int) $request->user()->getAuthIdentifier(),
            $data['name'],
        )));
    }

    public function destroy(Request $request, string $folder): Response
    {
        $this->service->delete($folder, (int) $request->user()->getAuthIdentifier());

        return response()->noContent();
    }

    public function attachTranscription(Request $request, string $folder, string $transcription): JsonResponse
    {
        return response()->json($this->toResponse($this->service->attachTranscription(
            $folder,
            (int) $request->user()->getAuthIdentifier(),
            $transcription,
        )));
    }

    public function detachTranscription(Request $request, string $folder, string $transcription): JsonResponse
    {
        return response()->json($this->toResponse($this->service->detachTranscription(
            $folder,
            (int) $request->user()->getAuthIdentifier(),
            $transcription,
        )));
    }

    /** @return array{id: string, userId: int, name: string, transcriptionIds: list<string>, createdAt: string|null, updatedAt: string|null} */
    private function toResponse(Folder $folder): array
    {
        return [
            'id' => $folder->id,
            'userId' => $folder->userId,
            'name' => $folder->name,
            'transcriptionIds' => $folder->transcriptionIds,
            'createdAt' => $folder->createdAt?->format(DATE_ATOM),
            'updatedAt' => $folder->updatedAt?->format(DATE_ATOM),
        ];
    }

    /** @return array{data: list<array{id: string, userId: int, name: string, transcriptionIds: list<string>, createdAt: string|null, updatedAt: string|null}>, meta: array{currentPage: int, lastPage: int, perPage: int, total: int}} */
    private function toPageResponse(FolderPage $page): array
    {
        return [
            'data' => array_map($this->toResponse(...), $page->data),
            'meta' => [
                'currentPage' => $page->currentPage,
                'lastPage' => $page->lastPage,
                'perPage' => $page->perPage,
                'total' => $page->total,
            ],
        ];
    }

    /** @return array{id: string, name: string, fileName: string, status: string, duration: float|null, createdAt: string|null, exports: list<array{id: string, format: string, status: string, downloadUrl: string|null}>} */
    private function toTranscriptionResponse(FolderTranscription $transcription): array
    {
        return [
            'id' => $transcription->id,
            'name' => $transcription->name,
            'fileName' => $transcription->fileName,
            'status' => $transcription->status,
            'duration' => $transcription->duration,
            'createdAt' => $transcription->createdAt?->format(DATE_ATOM),
            'exports' => array_map(fn (TranscriptionExport $export): array => [
                'id' => $export->id,
                'format' => $export->format,
                'status' => $export->status,
                'downloadUrl' => $this->exportUrlGenerator->generate($export, $transcription->name),
            ], $transcription->exports),
        ];
    }
}
