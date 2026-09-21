<?php

use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Folder\Entities\Folder;
use App\Domain\Folder\Entities\FolderPage;
use App\Domain\Folder\Entities\FolderTranscription;
use App\Domain\Folder\Services\FolderService;
use Mockery as M;

test('folder service keeps user ownership in every repository operation', function () {
    $folder = new Folder('01ARZ3NDEKTSV4RRFFQ69G5FAV', 7, 'Interviews');
    $repository = M::mock(FolderRepositoryInterface::class);
    $service = new FolderService($repository);
    $page = new FolderPage([$folder], 1, 1, 10, 1);
    $transcription = new FolderTranscription('01ARZ3NDEKTSV4RRFFQ69G5FAW', 'Interview', 'interview.mp3', 'completed');

    $repository->shouldReceive('paginateForUser')->once()->with(7, 'inter', 'name', 'asc', 2, 10)->andReturn($page);
    $repository->shouldReceive('create')->once()->with(7, 'Interviews')->andReturn($folder);
    $repository->shouldReceive('transcriptionsForUser')->once()->with($folder->id, 7)->andReturn([$transcription]);
    $repository->shouldReceive('findOrCreateByName')->once()->with(7, 'Interviews')->andReturn($folder);
    $repository->shouldReceive('update')->once()->with($folder->id, 7, 'Research')->andReturn($folder);
    $repository->shouldReceive('attachTranscription')->once()->with($folder->id, 7, '01ARZ3NDEKTSV4RRFFQ69G5FAW')->andReturn($folder);
    $repository->shouldReceive('detachTranscription')->once()->with($folder->id, 7, '01ARZ3NDEKTSV4RRFFQ69G5FAW')->andReturn($folder);
    $repository->shouldReceive('delete')->once()->with($folder->id, 7)->andReturnTrue();

    expect($service->paginateForUser(7, 'inter', 'name', 'asc', 2, 10))->toBe($page);
    expect($service->create(7, 'Interviews'))->toBe($folder);
    expect($service->transcriptionsForUser($folder->id, 7))->toBe([$transcription]);
    expect($service->findOrCreateByName(7, 'Interviews'))->toBe($folder);
    expect($service->update($folder->id, 7, 'Research'))->toBe($folder);
    expect($service->attachTranscription($folder->id, 7, '01ARZ3NDEKTSV4RRFFQ69G5FAW'))->toBe($folder);
    expect($service->detachTranscription($folder->id, 7, '01ARZ3NDEKTSV4RRFFQ69G5FAW'))->toBe($folder);
    expect($service->delete($folder->id, 7))->toBeTrue();
});

afterEach(fn () => M::close());
