<?php

namespace App\Http\Responses\Folder;

use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderProject;
use App\Domain\Folder\Entities\FolderTranscription;
use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class FolderResponse extends ApiResponse
{
    public function __construct(private readonly TranscriptionExportUrlGeneratorInterface $exportUrlGenerator) {}

    public function toResponse(Folder $folder): array
    {
        return [
            'id' => $folder->id,
            'userId' => $folder->userId,
            'name' => $folder->name,
            'transcriptionIds' => $folder->transcriptionIds,
            'translationCount' => $folder->translationCount,
            'dubbingCount' => $folder->dubbingCount,
            'createdAt' => $folder->createdAt?->format(DATE_ATOM),
            'updatedAt' => $folder->updatedAt?->format(DATE_ATOM),
        ];
    }

    public function toPageResponse(FolderPage $page): array
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

    public function toTranscriptionResponse(FolderTranscription $transcription): array
    {
        return [
            'id' => $transcription->id,
            'name' => $transcription->name,
            'fileName' => $transcription->fileName,
            'status' => $transcription->status,
            'transcript' => $transcription->transcript,
            'provider' => $transcription->provider,
            'segments' => $transcription->segments,
            'audioUrl' => $transcription->audioUrl,
            'duration' => $transcription->duration,
            'createdAt' => $transcription->createdAt?->format(DATE_ATOM),
            'exports' => array_map(fn (TranscriptionExport $export): array => [
                'id' => $export->id,
                'format' => $export->format,
                'status' => $export->status,
                'downloadUrl' => $this->exportUrlGenerator->generate($export, $transcription->name),
                'variant' => $export->variant,
            ], $transcription->exports),
        ];
    }

    public function detail(Folder $folder, array $transcriptions, array $projects): JsonResponse
    {
        return $this->json([
            ...$this->toResponse($folder),
            'transcriptions' => array_map($this->toTranscriptionResponse(...), $transcriptions),
            'projects' => array_map(fn (FolderProject $project): array => [
                'id' => $project->id, 'name' => $project->name, 'type' => $project->type, 'status' => $project->status,
                'sourceLanguage' => $project->sourceLanguage, 'targetLanguage' => $project->targetLanguage,
                'createdAt' => $project->createdAt, 'mediaType' => $project->mediaType,
            ], $projects),
        ]);
    }
}
