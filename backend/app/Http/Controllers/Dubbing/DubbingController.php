<?php

namespace App\Http\Controllers\Dubbing;

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Dubbing\Services\DubbingLanguages;
use App\Domain\Dubbing\Services\DubbingService;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Dubbing\Services\SubtitleStyles;
use App\Domain\Dubbing\Services\VideoSubtitleLanguages;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DubbingController extends Controller
{
    public function __construct(private readonly DubbingService $service, private readonly DubbingMediaInterface $media) {}

    public function languages(DubbingLanguages $languages, BillingSettingsInterface $settings, DubbingGatewayResolverInterface $providers,
        VideoSubtitleLanguages $videoSubtitles): JsonResponse
    {
        $configured = false;
        $data = [];
        try {
            $definition = $providers->definition();
            $configured = $definition['configured'];
            $settings->rate('dubbing', $definition['provider'], $definition['model']);
            $data = $configured ? $languages->all() : [];
        } catch (BillingException) {
            $configured = false;
        }
        $audioConfigured = false;
        $audioData = [];
        try {
            $definition = $providers->definition('audio');
            $settings->rate('dubbing', $definition['provider'], $definition['model']);
            $audioConfigured = $definition['configured'];
            $audioData = $audioConfigured ? $languages->all('audio') : [];
        } catch (BillingException) {
            $audioConfigured = false;
        }

        return response()->json(['data' => $data, 'sourceLanguages' => $languages->sourceLanguages(),
            'subtitleStyles' => SubtitleStyles::catalog(), 'configured' => $configured, 'subtitlesOnly' => $videoSubtitles->catalog(),
            'audioDubbing' => ['configured' => $audioConfigured, 'data' => $audioData, 'sourceLanguages' => $languages->sourceLanguages()]]);
    }

    public function quote(Request $request, CreditService $credits): JsonResponse
    {
        $input = $request->validate(['client_key' => ['required', 'uuid'],
            'media_type' => ['sometimes', Rule::in(['video', 'audio'])],
            'folder_id' => ['nullable', 'ulid'],
            'video_storage_path' => ['required_unless:media_type,audio', 'prohibited_if:media_type,audio', 'string', 'max:1024'],
            'audio_storage_path' => ['required_if:media_type,audio', 'prohibited_unless:media_type,audio', 'string', 'max:1024'],
            'operation' => ['sometimes', Rule::in(['dubbing', 'subtitles'])],
            'name' => ['nullable', 'string', 'max:255'], 'source_language' => ['nullable', 'string', 'max:20'], 'target_language' => ['required', 'string', 'max:100'],
            'subtitles_enabled' => ['sometimes', 'boolean'], 'subtitle_style' => ['nullable', 'string', Rule::in(SubtitleStyles::NAMES)]]);
        $key = $input['client_key'];
        unset($input['client_key']);
        $quote = $this->service->quote($request->user()->id, $input, $key);

        return response()->json($this->quoteData($quote, $credits), 202);
    }

    public function showQuote(Request $request, string $quote, BillingRepositoryInterface $repository, CreditService $credits): JsonResponse
    {
        $record = $repository->quote($quote, $request->user()->id) ?? throw new BillingException('Quote not found.', 404);
        if ($record['activity'] !== 'dubbing') {
            throw new BillingException('Quote not found.', 404);
        }

        return response()->json($this->quoteData($record, $credits));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate(['quote_id' => ['required', 'ulid']]);
        $record = $this->service->submit($request->user()->id, $input['quote_id']);

        return response()->json($this->representation($record), $record->status === 'pending' ? 202 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $result = $this->service->history($request->user()->id, (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 10));
        $result['data'] = array_map(fn ($item) => $this->representation($item, false), $result['data']);

        return response()->json($result);
    }

    public function show(Request $request, string $dubbing): JsonResponse
    {
        return response()->json($this->representation($this->service->find($dubbing, $request->user()->id)))->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $dubbing, ProcessDubbing $processor): JsonResponse
    {
        $processor->retryExports($dubbing, $request->user()->id);

        return $this->show($request, $dubbing);
    }

    private function quoteData(array $quote, CreditService $credits): array
    {
        $balance = $credits->balance($quote['user_id']);

        return array_intersect_key($quote, array_flip(['id', 'status', 'quantity', 'credit_units', 'expires_at', 'failure_reason', 'dubbing_id'])) + [
            'available_units' => $balance['available_units'], 'enough_credits' => $quote['credit_units'] !== null && $balance['available_units'] >= $quote['credit_units']];
    }

    private function representation(Dubbing $record, bool $downloads = true): array
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
}
