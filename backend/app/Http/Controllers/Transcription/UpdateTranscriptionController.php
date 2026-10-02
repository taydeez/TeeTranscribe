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
        ]);
        $updated = $this->service->edit(
            $transcription,
            (int) $request->user()->getAuthIdentifier(),
            $data['transcript'],
        );

        return response()->json([
            'id' => $updated->id,
            'transcript' => $updated->transcript,
            'status' => $updated->status,
        ]);
    }
}
