<?php

use App\Domain\Transcriber\Contracts\TranscriptionExportRepositoryInterface;
use App\Domain\Transcriber\Entities\TranscriptionExport;
use App\Domain\Transcriber\Exceptions\TranscriptionExportNotFoundException;
use App\Domain\Transcriber\Services\TranscriptionExportService;

test('returns the requested export when it exists', function () {
    $export = new TranscriptionExport('export-id', 'transcription-id', 'txt');
    $repository = $this->createMock(TranscriptionExportRepositoryInterface::class);
    $repository->expects($this->once())->method('find')->with('export-id')->willReturn($export);

    expect((new TranscriptionExportService($repository))->findOrFail('export-id'))->toBe($export);
});

test('reports a domain error when the requested export does not exist', function () {
    $repository = $this->createMock(TranscriptionExportRepositoryInterface::class);
    $repository->expects($this->once())->method('find')->with('missing')->willReturn(null);

    expect(fn () => (new TranscriptionExportService($repository))->findOrFail('missing'))
        ->toThrow(TranscriptionExportNotFoundException::class, 'Transcription export [missing] was not found.');
});
