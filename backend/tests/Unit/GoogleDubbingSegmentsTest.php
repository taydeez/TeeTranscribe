<?php

use App\Infrastructure\AI\Dubbing\Google\GoogleDubbingSegments;

function googleDubbingOperation(array $words): array
{
    return ['response' => ['results' => ['gs://bucket/audio' => ['inlineResult' => ['transcript' => [
        'results' => [['alternatives' => [['words' => $words]]]],
    ]]]]]];
}

test('Google dubbing retains zero offsets and splits phrases at speaker changes and sentence boundaries', function () {
    $segments = (new GoogleDubbingSegments)->parse(googleDubbingOperation([
        ['word' => 'Hello', 'endOffset' => '0.5s', 'speakerLabel' => '0'],
        ['word' => 'world.', 'startOffset' => '0.5s', 'endOffset' => '1s', 'speakerLabel' => '0'],
        ['word' => 'Bonjour.', 'startOffset' => '1s', 'endOffset' => '2s', 'speakerLabel' => '1'],
    ]), 3);

    expect($segments)->toHaveCount(2)->and($segments[0])->toMatchArray(['start' => 0.0, 'end' => 1.0, 'text' => 'Hello world.', 'speaker' => '0'])
        ->and($segments[1]['speaker'])->toBe('1');
});

test('Google dubbing refuses missing timestamps and overlapping speech rather than producing misaligned audio', function () {
    expect(fn () => (new GoogleDubbingSegments)->parse(googleDubbingOperation([['word' => 'Hello']]), 3))->toThrow(RuntimeException::class);
    expect(fn () => (new GoogleDubbingSegments)->parse(googleDubbingOperation([
        ['word' => 'Hello', 'endOffset' => '2s'], ['word' => 'world', 'startOffset' => '1s', 'endOffset' => '3s'],
    ]), 3))->toThrow(RuntimeException::class);
});

test('Google dubbing rejects silent sources and per-file recognition errors', function () {
    expect(fn () => (new GoogleDubbingSegments)->parse(['response' => ['results' => []]], 3))->toThrow(RuntimeException::class);
    expect(fn () => (new GoogleDubbingSegments)->parse(['response' => ['results' => [['error' => ['code' => 3]]]]], 3))->toThrow(RuntimeException::class);
});
