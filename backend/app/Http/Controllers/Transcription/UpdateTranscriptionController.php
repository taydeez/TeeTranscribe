<?php

namespace App\Http\Controllers\Transcription;

use App\Domain\Transcriber\Services\EditTranscriptionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateTranscriptionController extends Controller
{
    public function __construct(private readonly EditTranscriptionService $service) {}

    public function __invoke(Request $request, string $transcription): JsonResponse
    {
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:10000000'],
            'segments' => ['sometimes', 'array', 'list', 'min:1', 'max:20000'],
            'segments.*.text' => ['required', 'string', 'max:100000'],
            'segments.*.speaker' => ['nullable', 'string', 'max:100'],
        ]);
        $updated = $this->service->edit(
            $transcription,
            (int) $request->user()->getAuthIdentifier(),
            $data['transcript'],
            $data['segments'] ?? null,
        );

        return response()->json([
            'id' => $updated->id,
            'transcript' => $updated->transcript,
            'status' => $updated->status,
            'segments' => $updated->segments,
        ]);
    }
}
