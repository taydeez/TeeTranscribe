<?php

use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Services\TranscribeService;
use App\Infrastructure\AI\TranscriberGatewayResolver;

test('normalizes audio URLs and derives readable names before persistence', function () {
    $url = 'https://audio.example.com/Team%20meeting.mp3?signature=private';
    $entity = new Transcription(id: 'export-id', userId: null, audioPath: $url, fileName: 'Team meeting.mp3', name: 'Team meeting');
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->once())->method('create')->with([
        'audio_path' => $url, 'file_name' => 'Team meeting.mp3', 'name' => 'Team meeting', 'duration' => 42.5,
    ])->willReturn($entity);
    $service = new TranscribeService(new TranscriberGatewayResolver, $repository);

    expect($service->storeTranscription(['audio_url' => $url, 'language_code' => 'en', 'duration' => 42.5]))->toBe($entity);
});

test('preserves explicit names and folder when storing a transcription', function () {
    $data = [
        'audio_path' => 'audio/generated.mp3', 'file_name' => 'Original recording.mp3',
        'name' => 'Custom title', 'folder_name' => 'Meetings',
    ];
    $entity = new Transcription(
        id: 'transcription-id', userId: null, audioPath: $data['audio_path'],
        fileName: $data['file_name'], name: $data['name'], folderName: $data['folder_name'],
    );
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->once())->method('create')->with($data)->willReturn($entity);
    $service = new TranscribeService(new TranscriberGatewayResolver, $repository);

    expect($service->storeTranscription($data))->toBe($entity);
});

test('does not create a transcription without an audio location', function () {
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->never())->method('create');
    $service = new TranscribeService(new TranscriberGatewayResolver, $repository);

    expect(fn () => $service->storeTranscription([]))->toThrow(InvalidArgumentException::class);
});
