<?php

use App\Domain\Privacy\Services\RetentionPolicy;

test('keeping files forever is the default for every independently controlled category', function () {
    expect(RetentionPolicy::defaults())->toHaveCount(11);
    foreach (RetentionPolicy::defaults() as $value) {
        expect($value)->toBeNull();
    }
});

test('changing document retention does not change source or saved text retention', function () {
    $first = RetentionPolicy::merge([], ['recordings' => 4, 'transcripts' => 168]);
    $second = RetentionPolicy::merge($first, ['pdf' => 24, 'recordings' => null]);
    expect($second)->toMatchArray(['pdf' => 24, 'recordings' => null, 'transcripts' => 168, 'source_audio' => null, 'txt' => null]);
});
