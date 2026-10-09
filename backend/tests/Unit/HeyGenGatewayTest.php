<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Infrastructure\AI\Dubbing\HeyGen\HeyGenDubbingGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['dubbing.heygen.key' => 'test-key']);
    Http::preventStrayRequests();
});

test('HeyGen reports running as unfinished and completed video as the export source', function () {
    Http::fakeSequence()->push(['data' => ['id' => 'tr_test', 'status' => 'running', 'output_language' => 'French']])
        ->push(['data' => ['id' => 'tr_test', 'status' => 'completed', 'output_language' => 'French', 'video_url' => 'https://resource2.heygen.ai/video.mp4']]);
    $gateway = app(HeyGenDubbingGateway::class);
    expect($gateway->language('tr_test', 'tr_test')['status'])->toBe('running');
    expect($gateway->language('tr_test', 'tr_test')['outputs']['video'])->toBe('https://resource2.heygen.ai/video.mp4');
});

test('HeyGen recovery follows pagination and matches the applications exact reference', function () {
    Http::fakeSequence()->push(['data' => [['id' => 'unrelated', 'callback_id' => 'other', 'title' => 'dubbing:reference']], 'has_more' => true, 'next_token' => 'next'])
        ->push(['data' => [['id' => 'tr_match', 'callback_id' => 'reference', 'title' => 'dubbing:reference']], 'has_more' => false]);
    expect(app(HeyGenDubbingGateway::class)->recover('reference'))->toBe(['project_id' => 'tr_match', 'language_ids' => ['tr_match']]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'token=next'));
});

test('HeyGen refuses an unexpected translation identity or a completed response without video', function () {
    Http::fakeSequence()->push(['data' => ['id' => 'another', 'status' => 'completed', 'video_url' => 'https://resource2.heygen.ai/video.mp4']])
        ->push(['data' => ['id' => 'tr_test', 'status' => 'completed', 'video_url' => null]]);
    $gateway = app(HeyGenDubbingGateway::class);
    expect(fn () => $gateway->language('tr_test', 'tr_test'))->toThrow(BillingException::class, 'invalid translation');
    expect(fn () => $gateway->language('tr_test', 'tr_test'))->toThrow(BillingException::class, 'no completed video');
});
