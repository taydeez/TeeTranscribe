<?php

use App\Domain\Billing\Contracts\BillingRepositoryInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingRepositoryInterface;
use App\Domain\Dubbing\Contracts\DubbingSubtitleRendererInterface;
use App\Domain\Dubbing\Entities\Dubbing;
use App\Domain\Dubbing\Services\ProcessDubbingSubtitles;
use App\Domain\Privacy\Contracts\PrivacyCoordinatorInterface;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $privacy = Mockery::mock(PrivacyCoordinatorInterface::class);
    $privacy->shouldReceive('exclusive')->andReturnUsing(fn ($type, $id, $callback) => $callback());
    $privacy->shouldReceive('projectDeleted', 'generatedDeleted')->andReturn(false);
    app()->instance(PrivacyCoordinatorInterface::class, $privacy);
});

test('duplicate subtitle work for a completed video skips the provider and renderer', function () {
    $record = new Dubbing('dub-id', 1, 'Interview', 'source.mp4', 'en', 'fr', 1000, 'complete', subtitlesEnabled: true);
    $records = Mockery::mock(DubbingRepositoryInterface::class);
    $records->shouldReceive('find')->once()->with('dub-id')->andReturn($record);
    $records->shouldNotReceive('update');
    $billing = Mockery::mock(BillingRepositoryInterface::class);
    $billing->shouldReceive('exclusive')->once()->with('dubbing:subtitles:dub-id', Mockery::type(Closure::class))
        ->andReturnUsing(fn ($key, $operation) => $operation());
    $providers = Mockery::mock(DubbingGatewayResolverInterface::class);
    $providers->shouldNotReceive('resolve');
    $renderer = Mockery::mock(DubbingSubtitleRendererInterface::class);
    $renderer->shouldNotReceive('render');
    (new ProcessDubbingSubtitles($records, $billing, $providers, $renderer, app(PrivacyCoordinatorInterface::class)))->handle('dub-id');
});

test('subtitle retries reuse saved captions without needing the speech provider', function () {
    $record = new Dubbing('dub-id', 1, 'Interview', 'source.mp4', 'en', 'fr', 1000, 'processing', providerCompletedAt: '2026-10-09T00:00:00Z',
        audioStoragePath: 'audio.flac', videoStoragePath: 'video.mp4', subtitlesEnabled: true, subtitleStoragePath: 'captions.srt');
    $records = Mockery::mock(DubbingRepositoryInterface::class);
    $records->shouldReceive('find')->once()->with('dub-id')->andReturn($record);
    $records->shouldReceive('update')->once()->with('dub-id', ['subtitle_status' => 'processing'])->andReturn($record);
    $records->shouldReceive('update')->once()->with('dub-id', ['subtitle_storage_path' => 'captions.srt', 'captioned_video_storage_path' => 'captioned.mp4',
        'subtitle_status' => 'complete', 'status' => 'complete', 'failure_reason' => null])->andReturn($record);
    $billing = Mockery::mock(BillingRepositoryInterface::class);
    $billing->shouldReceive('exclusive')->once()->andReturnUsing(fn ($key, $operation) => $operation());
    $billing->shouldReceive('transaction')->once()->andReturnUsing(fn ($operation) => $operation());
    $providers = Mockery::mock(DubbingGatewayResolverInterface::class);
    $providers->shouldNotReceive('resolve');
    $renderer = Mockery::mock(DubbingSubtitleRendererInterface::class);
    $renderer->shouldReceive('render')->once()->with($record, ['storage_path' => 'captions.srt'])
        ->andReturn(['subtitle_storage_path' => 'captions.srt', 'captioned_video_storage_path' => 'captioned.mp4']);
    (new ProcessDubbingSubtitles($records, $billing, $providers, $renderer, app(PrivacyCoordinatorInterface::class)))->handle('dub-id');
});
