<?php

use App\Domain\Dubbing\Services\DubbingLanguages;

test('the dubbing catalog keeps Nigerian supported languages first without dropping global dialects', function () {
    $languages = (new DubbingLanguages)->all();
    expect(array_slice(array_column($languages, 'code'), 0, 3))->toBe(['yo', 'ha', 'en']);
    $codes = array_column($languages, 'code');
    expect($codes)->toContain('es-MX', 'en-GB', 'ja', 'zh', 'ar-EG')->not->toContain('ig', 'pcm');
    expect(array_column(array_filter($languages, fn ($item) => $item['nigerian']), 'code'))->toBe(['yo', 'ha']);
});
