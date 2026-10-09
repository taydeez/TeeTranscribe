<?php

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Billing\Contracts\BillingSettingsInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Contracts\VideoSubtitleTranscriberInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Dubbing\Services\PrepareVideoSubtitles;
use App\Domain\Dubbing\Services\SubtitleDocument;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use Tests\TestCase;

uses(TestCase::class);

test('saved translated subtitles resume original media preparation without any paid provider calls', function () {
    $record = new Dubbing('video-id', 1, 'Interview', 'english.mp4', 'en', 'es', 1000, 'processing',
        providerCompletedAt: '2026-10-09T00:00:00Z', provider: 'deepgram', model: 'nova-2', subtitlesEnabled: true,
        operation: 'subtitles', sourceSubtitleSegments: [['start' => 0, 'end' => 1, 'text' => 'Hello.']],
        translatedSubtitleSegments: [['start' => 0, 'end' => 1, 'text' => 'Hola.']]);
    $records = Mockery::mock(DubbingRepositoryInterface::class);
    $records->shouldReceive('update')->once()->with('video-id', ['status' => 'processing'])->andReturn($record);
    $records->shouldReceive('update')->once()->with('video-id', ['video_storage_path' => 'english.mp4', 'audio_storage_path' => 'english.flac'])->andReturn($record);
    $records->shouldReceive('update')->once()->with('video-id', ['subtitle_status' => 'pending', 'failure_reason' => null])->andReturn($record);
    $records->shouldReceive('enqueueSubtitles')->once()->with('video-id');
    $billing = Mockery::mock(BillingRepositoryInterface::class);
    $billing->shouldReceive('transaction')->once()->andReturnUsing(fn ($operation) => $operation());
    $transcriber = Mockery::mock(VideoSubtitleTranscriberInterface::class);
    $transcriber->shouldNotReceive('transcribe');
    $translator = Mockery::mock(TranslationGatewayInterface::class);
    $translator->shouldNotReceive('translate');
    $media = Mockery::mock(DubbingMediaInterface::class);
    $media->shouldReceive('prepareOriginal')->once()->with($record)->andReturn(['video_storage_path' => 'english.mp4', 'audio_storage_path' => 'english.flac']);
    $media->shouldNotReceive('store', 'storeVideo', 'sourceUrl');
    $billing->shouldNotReceive('charge', 'lockWallet');
    $credits = new CreditService($billing, Mockery::mock(BillingSettingsInterface::class));
    (new PrepareVideoSubtitles($records, $billing, $transcriber, $translator, $media, $credits, new SubtitleDocument))->handle($record);
});
