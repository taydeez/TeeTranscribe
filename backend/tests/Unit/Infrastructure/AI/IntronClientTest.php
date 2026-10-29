<?php

use App\Infrastructure\AI\Transcriber\Intron\IntronClient;

test('it normalizes supported Nigerian locale codes for Intron', function (string $input, string $expected) {
    expect((new IntronClient)->language($input))->toBe($expected);
})->with([
    ['en-NG', 'en'],
    ['pcm-NG', 'pcm'],
    ['yo-NG', 'yo'],
    ['ig-NG', 'ig'],
    ['ha-NG', 'ha'],
]);

test('it preserves the base code for other locales', function () {
    expect((new IntronClient)->language('sw-KE'))->toBe('sw');
});
