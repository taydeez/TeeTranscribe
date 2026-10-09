<?php

use App\Domain\Transcriber\Services\GoogleTranscriptionCapabilities;
use App\Domain\Transcriber\Services\TimedTranscriptSegments;

test('Google speaker capability reflects the supported locale rather than just the language', function () {
    expect(GoogleTranscriptionCapabilities::speakers('en'))->toBeTrue()
        ->and(GoogleTranscriptionCapabilities::speakers('en-AU'))->toBeFalse()
        ->and(GoogleTranscriptionCapabilities::speakers('yo'))->toBeFalse()
        ->and(GoogleTranscriptionCapabilities::speakers('ha'))->toBeFalse()
        ->and(GoogleTranscriptionCapabilities::locale('fr-CA'))->toBe('fr-CA');
    expect(fn () => GoogleTranscriptionCapabilities::locale('ig'))->toThrow(InvalidArgumentException::class);
});
test('speaker turns long pauses and long utterances form separate segments', function () {
    $words = [
        ['start' => 0.0, 'end' => 0.5, 'text' => 'Hello', 'speaker' => 'Speaker 1'],
        ['start' => 0.6, 'end' => 1.0, 'text' => 'there.', 'speaker' => 'Speaker 1'],
        ['start' => 1.1, 'end' => 2.0, 'text' => 'Hi.', 'speaker' => 'Speaker 2'],
        ['start' => 5.0, 'end' => 6.0, 'text' => 'Again.', 'speaker' => 'Speaker 2'],
        ['start' => 6.0, 'end' => 22.0, 'text' => 'Long.', 'speaker' => 'Speaker 2'],
    ];
    $segments = TimedTranscriptSegments::fromWords($words);
    expect($segments)->toHaveCount(4)->and($segments[0]['text'])->toBe('Hello there.')
        ->and($segments[0]['end'])->toBe(1.0)->and($segments[1]['speaker'])->toBe('Speaker 2');
});
