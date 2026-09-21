<?php

use App\Domain\Folder\Services\FolderService;
use App\Domain\Transcriber\Contracts\TranscriptionExportUrlGeneratorInterface;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated users can create read update and delete their folders', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $created = $this->postJson('/api/v1/folders', ['name' => 'Interviews'])
        ->assertCreated()
        ->assertJsonPath('name', 'Interviews')
        ->assertJsonPath('userId', $user->id)
        ->assertJsonPath('transcriptionIds', []);

    $folderId = $created->json('id');
    expect(Str::isUlid($folderId))->toBeTrue();

    $this->getJson('/api/v1/folders')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $folderId)
        ->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/folders/{$folderId}")->assertOk()->assertJsonPath('name', 'Interviews');
    $this->putJson("/api/v1/folders/{$folderId}", ['name' => 'Client interviews'])
        ->assertOk()->assertJsonPath('name', 'Client interviews');

    $this->deleteJson("/api/v1/folders/{$folderId}")->assertNoContent();
    $this->assertDatabaseMissing('folders', ['id' => $folderId]);
});

test('a folder exposes its transcriptions through the ulid pivot', function () {
    $user = User::factory()->create();
    $folder = Folder::query()->create(['user_id' => $user->id, 'name' => 'Meetings']);
    $transcription = Transcription::factory()->create(['user_id' => $user->id, 'guest_session_id' => null]);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/folders/{$folder->id}/transcriptions/{$transcription->id}")
        ->assertOk()->assertJsonPath('transcriptionIds.0', $transcription->id);

    $pivotId = $folder->fresh()->transcriptions->first()->pivot->id;
    expect(Str::isUlid($pivotId))->toBeTrue();
    expect($folder->fresh()->transcriptions->modelKeys())->toBe([$transcription->id]);
    expect($transcription->fresh()->folders->modelKeys())->toBe([$folder->id]);

    $this->deleteJson("/api/v1/folders/{$folder->id}/transcriptions/{$transcription->id}")
        ->assertOk()->assertJsonPath('transcriptionIds', []);
    $this->assertDatabaseMissing('folder_transcription', ['folder_id' => $folder->id, 'transcription_id' => $transcription->id]);
});

test('opening a folder lists its transcriptions with completed export download links', function () {
    $user = User::factory()->create();
    $folder = Folder::query()->create(['user_id' => $user->id, 'name' => 'Interviews']);
    $transcription = Transcription::factory()->create([
        'user_id' => $user->id,
        'guest_session_id' => null,
        'name' => 'Odega Interview',
        'file_name' => 'odega.mp3',
        'duration' => 95,
        'status' => 'complete',
    ]);
    $folder->transcriptions()->attach($transcription->id);
    TranscriptionExport::factory()->create([
        'transcription_id' => $transcription->id,
        'format' => 'txt',
        'status' => 'completed',
        'storage_path' => "exports/{$transcription->id}/transcript.txt",
    ]);
    TranscriptionExport::factory()->create([
        'transcription_id' => $transcription->id,
        'format' => 'pdf',
        'status' => 'completed',
        'storage_path' => "exports/{$transcription->id}/transcript.pdf",
    ]);
    $this->mock(TranscriptionExportUrlGeneratorInterface::class, function ($mock): void {
        $mock->shouldReceive('generate')->twice()
            ->andReturnUsing(fn ($export, string $name): string => "https://downloads.example.com/{$name}.{$export->format}");
    });
    Sanctum::actingAs($user);

    $this->getJson("/api/v1/folders/{$folder->id}")
        ->assertOk()
        ->assertJsonPath('name', 'Interviews')
        ->assertJsonPath('transcriptions.0.name', 'Odega Interview')
        ->assertJsonPath('transcriptions.0.duration', 95)
        ->assertJsonFragment(['format' => 'txt', 'downloadUrl' => 'https://downloads.example.com/Odega Interview.txt'])
        ->assertJsonFragment(['format' => 'pdf', 'downloadUrl' => 'https://downloads.example.com/Odega Interview.pdf']);
});

test('folder access and transcription assignment are restricted to the owner', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $folder = Folder::query()->create(['user_id' => $user->id, 'name' => 'Private']);
    $otherTranscription = Transcription::factory()->create(['user_id' => $otherUser->id, 'guest_session_id' => null]);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/folders/{$folder->id}/transcriptions/{$otherTranscription->id}")
        ->assertUnprocessable();

    Sanctum::actingAs($otherUser);
    $this->getJson("/api/v1/folders/{$folder->id}")->assertNotFound();
    $this->deleteJson("/api/v1/folders/{$folder->id}")->assertNotFound();
});

test('folder endpoints require authentication', function () {
    $this->getJson('/api/v1/folders')->assertUnauthorized();
    $this->postJson('/api/v1/folders', ['name' => 'Private'])->assertUnauthorized();
});

test('folder list is searchable sortable paginated and scoped to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $older = Folder::query()->create(['user_id' => $user->id, 'name' => 'Archived calls']);
    $older->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
    $newer = Folder::query()->create(['user_id' => $user->id, 'name' => 'Client Calls']);
    $company = Folder::query()->create(['user_id' => $user->id, 'name' => 'Company calls']);
    $company->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();
    Folder::query()->create(['user_id' => $otherUser->id, 'name' => 'Calls from another user']);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/folders?search=CALLS&sort=name&direction=asc&per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Archived calls')
        ->assertJsonPath('data.1.name', 'Client Calls')
        ->assertJsonPath('meta.currentPage', 1)
        ->assertJsonPath('meta.lastPage', 2)
        ->assertJsonPath('meta.perPage', 2)
        ->assertJsonPath('meta.total', 3);

    $this->getJson('/api/v1/folders?sort=created_at&direction=desc')
        ->assertOk()->assertJsonPath('data.0.id', $newer->id);
    $this->getJson('/api/v1/folders?sort=created_at&direction=asc')
        ->assertOk()->assertJsonPath('data.0.id', $older->id);
});

test('finding or creating a named folder reuses the existing user folder', function () {
    $user = User::factory()->create();
    $service = app(FolderService::class);

    $created = $service->findOrCreateByName($user->id, 'September 20, 2026');
    $reused = $service->findOrCreateByName($user->id, 'September 20, 2026');

    expect($reused->id)->toBe($created->id);
    $this->assertDatabaseCount('folders', 1);
});
