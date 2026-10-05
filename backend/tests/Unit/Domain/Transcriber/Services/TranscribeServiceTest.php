<?php

use App\Domain\Transcriber\Contracts\TranscriberGatewayInterface;
use App\Domain\Transcriber\Contracts\TranscriberGatewayResolverInterface;
use App\Domain\Transcriber\Contracts\TranscriptionRepositoryInterface;
use App\Domain\Transcriber\Contracts\TranscriptionSubmissionDispatcherInterface;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Services\TranscribeService;

test('normalizes audio URLs and dispatches asynchronous submission', function () {
    $url = 'https://audio.example.com/Team%20meeting.mp3?signature=private';
    $entity = new Transcription(
        id: 'export-id', userId: null, audioPath: $url, fileName: 'Team meeting', name: 'Team meeting', duration: 42.5,
    );
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->once())->method('create')->with([
        'audio_path' => $url, 'file_name' => 'Team meeting', 'name' => 'Team meeting', 'duration' => 42.5, 'provider' => 'deepgram',
    ])->willReturn($entity);
    $gateway = $this->createMock(TranscriberGatewayInterface::class);
    $gateway->method('provider')->willReturn('deepgram');
    $gateway->expects($this->never())->method('transcribe');
    $resolver = $this->createMock(TranscriberGatewayResolverInterface::class);
    $resolver->expects($this->once())->method('resolve')->with('en')->willReturn($gateway);
    $dispatcher = $this->createMock(TranscriptionSubmissionDispatcherInterface::class);
    $dispatcher->expects($this->once())->method('dispatch')->with($entity, 'en');
    $service = new TranscribeService($resolver, $repository, $dispatcher);

    expect($service->startNewTranscription(['audio_url' => $url, 'language_code' => 'en', 'duration' => 42.5]))->toBe($entity);
});

test('preserves explicit names and folder when starting a transcription', function () {
    $data = [
        'audio_path' => 'audio/generated.mp3', 'file_name' => 'Original recording.mp3',
        'name' => 'Custom title', 'folder_name' => 'Meetings', 'language_code' => 'en',
    ];
    $entity = new Transcription(
        id: 'transcription-id', userId: null, audioPath: $data['audio_path'],
        fileName: $data['file_name'], name: $data['name'], folderName: $data['folder_name'],
    );
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->once())->method('create')->with([
        'audio_path' => 'audio/generated.mp3', 'file_name' => 'Original recording.mp3',
        'name' => 'Custom title', 'folder_name' => 'Meetings', 'provider' => 'deepgram',
    ])->willReturn($entity);
    $gateway = $this->createMock(TranscriberGatewayInterface::class);
    $gateway->method('provider')->willReturn('deepgram');
    $resolver = $this->createMock(TranscriberGatewayResolverInterface::class);
    $resolver->method('resolve')->willReturn($gateway);
    $dispatcher = $this->createMock(TranscriptionSubmissionDispatcherInterface::class);
    $dispatcher->expects($this->once())->method('dispatch')->with($entity, 'en');
    $service = new TranscribeService($resolver, $repository, $dispatcher);

    expect($service->startNewTranscription($data))->toBe($entity);
});

test('does not create a transcription without an audio location', function () {
    $repository = $this->createMock(TranscriptionRepositoryInterface::class);
    $repository->expects($this->never())->method('create');
    $resolver = $this->createMock(TranscriberGatewayResolverInterface::class);
    $dispatcher = $this->createMock(TranscriptionSubmissionDispatcherInterface::class);
    $service = new TranscribeService($resolver, $repository, $dispatcher);

    expect(fn () => $service->startNewTranscription([]))->toThrow(InvalidArgumentException::class);
});
