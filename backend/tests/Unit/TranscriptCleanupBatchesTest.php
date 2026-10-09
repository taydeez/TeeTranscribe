<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Transcriber\Services\TranscriptCleanupBatches;

test('cleanup pieces retain every Unicode character and map back to their original speakers', function () {
    $sources = [str_repeat('Ẹ káàárọ̀ 世界 👋. ', 800), str_repeat('Другой спикер. ', 250), 'Final speaker.'];
    $segments = array_map(fn ($text) => ['text' => $text, 'speaker' => 'Speaker', 'start' => 1, 'end' => 2], $sources);
    $plan = TranscriptCleanupBatches::plan(implode("\n", $sources), $segments);
    $pieces = array_fill(0, count($sources), '');
    foreach ($plan as $batch) {
        expect(strlen(json_encode(['segments' => array_column($batch, 'text')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))
            ->toBeLessThanOrEqual(8000)->and(count($batch))->toBeLessThanOrEqual(250);
        foreach ($batch as $unit) {
            expect(mb_check_encoding($unit['text'], 'UTF-8'))->toBeTrue()->and(strlen($unit['text']))->toBeLessThanOrEqual(6000);
            $pieces[$unit['segment']] .= $unit['text'];
        }
    }
    expect($pieces)->toBe($sources)->and($segments[0]['start'])->toBe(1)->and(count($plan))->toBeLessThanOrEqual(15);
});

test('plain cleanup prefers paragraph boundaries and reconstructs text exactly', function () {
    $text = str_repeat('a', 3500)."\n\n".str_repeat('b', 4000).' '.str_repeat('c', 5000);
    $plan = TranscriptCleanupBatches::plan($text, []);
    $units = array_merge(...$plan);
    expect($units[0])->toBe(['segment' => 0, 'text' => str_repeat('a', 3500)."\n\n"])
        ->and(implode('', array_column($units, 'text')))->toBe($text)
        ->and(array_unique(array_column($units, 'segment')))->toBe([0]);
});

test('JSON escaping counts toward the cleanup byte limit without losing quotes or control characters', function () {
    $text = str_repeat('"\\'."\t", 9000);
    $plan = TranscriptCleanupBatches::plan($text, []);
    $units = array_merge(...$plan);
    expect(implode('', array_column($units, 'text')))->toBe($text);
    foreach ($plan as $batch) {
        expect(strlen(json_encode(['segments' => array_column($batch, 'text')], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)))
            ->toBeLessThanOrEqual(8000);
    }
});

test('two thousand five hundred short speaker segments fit within the bounded request budget', function () {
    $segments = array_fill(0, 2500, ['text' => 'Hello.']);
    $plan = TranscriptCleanupBatches::plan('Hello.', $segments);
    expect($plan)->toHaveCount(10)->and(array_sum(array_map('count', $plan)))->toBe(2500)
        ->and($plan[9][249])->toBe(['segment' => 2499, 'text' => 'Hello.']);
});

test('oversized multilingual cleanup is rejected before any request plan can be used', function () {
    expect(fn () => TranscriptCleanupBatches::plan(str_repeat('界', 50000), []))
        ->toThrow(BillingException::class, 'too many cleanup requests');
});
