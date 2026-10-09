<?php

namespace App\Http\Responses\Dubbing;

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class DubbingResponse extends ApiResponse
{
    public function __construct(private readonly DubbingMediaInterface $media) {}

    public function quoteData(array $quote, CreditService $credits): array
    {
        $balance = $credits->balance($quote['user_id']);

        return array_intersect_key($quote, array_flip(['id', 'status', 'quantity', 'credit_units', 'expires_at', 'failure_reason', 'dubbing_id'])) + [
            'available_units' => $balance['available_units'], 'enough_credits' => $quote['credit_units'] !== null && $balance['available_units'] >= $quote['credit_units']];
    }

    public function representation(Dubbing $record, bool $downloads = true): array
    {
        $data = ['id' => $record->id, 'name' => $record->name, 'operation' => $record->operation, 'mediaType' => $record->mediaType,
            'folderId' => $record->folderId,
            'sourceLanguage' => $record->sourceLanguage ?? $record->detectedSourceLanguage, 'targetLanguage' => $record->targetLanguage,
            'durationMs' => $record->durationMs, 'status' => $record->status, 'createdAt' => $record->createdAt,
            'failureReason' => $record->failureReason, 'canRetryExports' => $record->status === 'failed' && $record->providerCompletedAt !== null,
            'subtitlesEnabled' => $record->subtitlesEnabled, 'subtitleStyle' => $record->subtitleStyle, 'subtitleStatus' => $record->subtitleStatus];
        if ($downloads) {
            $complete = $record->status === 'complete';
            $videoPath = $record->mediaType === 'audio' ? null : ($record->subtitlesEnabled ? $record->captionedVideoStoragePath : $record->videoStoragePath);
            $data += ['videoUrl' => $complete ? $this->media->url($videoPath, $record->name.'.mp4') : null,
                'videoDownloadUrl' => $complete ? $this->media->url($videoPath, $record->name.'.mp4', true) : null,
                'audioDownloadUrl' => $complete ? $this->media->url($record->audioStoragePath, $record->name.'.flac', true) : null,
                'audioUrl' => $complete ? $this->media->url($record->audioPreviewStoragePath, $record->name.'.mp3') : null,
                'audioMp3DownloadUrl' => $complete ? $this->media->url($record->audioPreviewStoragePath, $record->name.'.mp3', true) : null,
                'subtitleDownloadUrl' => $complete && $record->subtitlesEnabled ? $this->media->url($record->subtitleStoragePath, $record->name.'.srt', true) : null,
                'cleanVideoDownloadUrl' => $complete && $record->subtitlesEnabled ? $this->media->url($record->videoStoragePath, $record->name.'.mp4', true) : null];
        }

        return $data;
    }

    public function store(Dubbing $record): JsonResponse
    {
        return $this->json($this->representation($record), $record->status === 'pending' ? 202 : 200);
    }

    public function show(Dubbing $record): JsonResponse
    {
        return $this->json($this->representation($record))->header('Cache-Control', 'private, no-store');
    }

    public function history(array $history): JsonResponse
    {
        $history['data'] = array_map(fn (Dubbing $record): array => $this->representation($record, false), $history['data']);

        return $this->json($history);
    }
}
