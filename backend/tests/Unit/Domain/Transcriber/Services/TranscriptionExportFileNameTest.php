<?php

use App\Domain\Transcriber\Services\TranscriptionExportFileName;

test('creates safe export filenames from the transcription name', function () {
    expect(TranscriptionExportFileName::make('Odega Interview', 'pdf'))->toBe('Odega Interview.pdf')
        ->and(TranscriptionExportFileName::make('Client / Interview: One', 'txt'))->toBe('Client - Interview- One.txt');
});
