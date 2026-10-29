<?php

use App\Domain\Transcriber\Contracts\TranscriptionExportDispatcherInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Entities\TranscriptionEditResult;
use App\Domain\Transcriber\Services\EditTranscriptionService;

test('it persists an owned transcript before dispatching replacement exports', function () {
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $dispatcher = $this->createMock(TranscriptionExportDispatcherInterface::class);
    $updated = new Transcription(
        id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        userId: 12,
        audioPath: 'audio/interview.mp3',
        fileName: 'interview',
        name: 'Interview',
        status: 'processing',
        transcript: 'Corrected transcript.',
    );
    $repository->expects($this->once())
        ->method('updateTranscriptForUser')
        ->with($updated->id, 12, 'Corrected transcript.')
        ->willReturn(new TranscriptionEditResult($updated, true));
    $dispatcher->expects($this->once())
        ->method('dispatch')
        ->with($updated->id);

    $result = (new EditTranscriptionService($repository, $dispatcher))->edit(
        $updated->id,
        12,
        'Corrected transcript.',
    );

    expect($result)->toBe($updated);
});

test('it does not dispatch exports for unchanged text', function () {
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $dispatcher = $this->createMock(TranscriptionExportDispatcherInterface::class);
    $record = new Transcription(id: 'same', userId: 12, audioPath: 'audio.mp3', fileName: 'audio', name: 'Audio', status: 'complete', transcript: 'Same.');
    $repository->expects($this->once())->method('updateTranscriptForUser')->willReturn(new TranscriptionEditResult($record, false));
    $dispatcher->expects($this->never())->method('dispatch');
    expect((new EditTranscriptionService($repository, $dispatcher))->edit('same', 12, 'Same.'))->toBe($record);
});
