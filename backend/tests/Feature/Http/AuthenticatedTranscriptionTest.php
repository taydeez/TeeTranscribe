<?php

use App\Domain\Folder\Contracts\FolderRepositoryInterface;
use App\Domain\Folder\Entities\Folder;
use App\Domain\Transcriber\Entities\Transcription;
use App\Domain\Transcriber\Services\TranscribeService;
use App\Infrastructure\Persistence\Eloquent\Models\GuestSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('uses the authenticated user and removes the guest session from a transcription request', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $guestSession = GuestSession::query()->create([
        'token_hash' => hash('sha256', 'guest-token'),
        'expires_at' => now()->addHour(),
    ]);
    $url = 'https://audio.example.com/audio/interview.mp3';

    Sanctum::actingAs($user);
    $folder = new Folder('01ARZ3NDEKTSV4RRFFQ69G5FAW', $user->id, now()->format('F j, Y'));

    $this->mock(TranscribeService::class, function ($mock) use ($user, $url) {
        $mock->shouldReceive('startNewTranscription')->once()->with([
            'audio_url' => $url,
            'user_id' => $user->id,
            'language_code' => 'en',
        ])->andReturn(new Transcription(
            id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            userId: $user->id,
            audioPath: $url,
            fileName: 'interview.mp3',
            name: 'interview',
        ));
    });
    $folderRepository = Mockery::mock(FolderRepositoryInterface::class);
    $folderRepository->shouldReceive('findOrCreateByName')->once()
        ->with($user->id, now()->format('F j, Y'))->andReturn($folder);
    $folderRepository->shouldReceive('attachTranscription')->once()
        ->with($folder->id, $user->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV')->andReturn($folder);
    $this->instance(FolderRepositoryInterface::class, $folderRepository);

    $this->postJson('/api/v1/transcribe', [
        'audio_url' => $url,
        'language_code' => 'en',
        'user_id' => $otherUser->id,
        'guest_session_id' => $guestSession->id,
    ])->assertAccepted();
});

test('attaches an authenticated transcription to the selected folder', function () {
    $user = User::factory()->create();
    $folder = new Folder('01ARZ3NDEKTSV4RRFFQ69G5FAW', $user->id, 'Interviews');
    $url = 'https://audio.example.com/audio/interview.mp3';

    Sanctum::actingAs($user);

    $this->mock(TranscribeService::class, function ($mock) use ($user, $url) {
        $mock->shouldReceive('startNewTranscription')->once()->with([
            'audio_url' => $url,
            'user_id' => $user->id,
            'language_code' => 'en',
        ])->andReturn(new Transcription(
            id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            userId: $user->id,
            audioPath: $url,
            fileName: 'interview.mp3',
            name: 'interview',
        ));
    });
    $folderRepository = Mockery::mock(FolderRepositoryInterface::class);
    $folderRepository->shouldReceive('findForUser')->once()->with($folder->id, $user->id)->andReturn($folder);
    $folderRepository->shouldReceive('attachTranscription')->once()
        ->with($folder->id, $user->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV')->andReturn($folder);
    $this->instance(FolderRepositoryInterface::class, $folderRepository);

    $this->postJson('/api/v1/transcribe', [
        'audio_url' => $url,
        'language_code' => 'en',
        'folder_id' => $folder->id,
    ])->assertAccepted();
});
