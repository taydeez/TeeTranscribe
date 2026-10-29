<?php

use App\Domain\Billing\Entities\ActivityTask;
use App\Domain\Billing\Services\ActivityDeadline;

test('deadlines scale with media length and use provider overrides or activity defaults', function (string $provider, float $duration, string $expected) {
    $task = new ActivityTask('transcription', 'task', 1, 'transcription', $provider, 'pending', $duration, new DateTimeImmutable('2026-10-21T12:00:00Z'));
    $rules = ['transcription' => [
        'default' => ['base_minutes' => 10, 'duration_multiplier' => 2, 'max_minutes' => 120],
        'google' => ['base_minutes' => 30, 'duration_multiplier' => 3, 'max_minutes' => 120],
    ]];

    expect(ActivityDeadline::at($task, $rules)->format('H:i'))->toBe($expected);
})->with([
    'short recording' => ['deepgram', 60, '12:12'],
    'long recording' => ['deepgram', 1800, '13:10'],
    'rounds up partial minutes' => ['deepgram', 61, '12:13'],
    'provider override' => ['google', 600, '13:00'],
    'maximum cap' => ['google', 10800, '14:00'],
    'no media length' => ['unknown', 0, '12:10'],
]);
