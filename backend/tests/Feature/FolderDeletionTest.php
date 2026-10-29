<?php

use App\Domain\Privacy\Contracts\PrivacyRepositoryInterface;
use App\Domain\Privacy\Contracts\PrivacyStorageInterface;
use App\Domain\Privacy\Services\PrivacyService;
use App\Domain\Privacy\Services\ProcessPrivacyDeletion;
use App\Infrastructure\AI\Transcriber\TranscriptionCompletion;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\PrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('r2');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function folderDeletionUpload(User $user): UploadSession
{
    return UploadSession::create(['user_id' => $user->id, 'client_key' => (string) Str::uuid(),
        'filename' => 'interview.mp3', 'content_type' => 'audio/mpeg', 'size' => 1234,
        'fingerprint' => str_repeat('a', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp3',
        'part_size' => 16777216, 'expires_at' => now()->addDay(), 'status' => 'completed']);
}

test('folder deletion removes all owned projects and their source and generated files', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Remove me']);
    $upload = folderDeletionUpload($this->user);
    $transcript = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path]);
    $folder->transcriptions()->attach($transcript->id);
    $translation = Translation::factory()->create(['user_id' => $this->user->id, 'folder_id' => $folder->id]);
    $dub = Dubbing::factory()->create(['user_id' => $this->user->id, 'folder_id' => $folder->id, 'source_storage_path' => $upload->storage_path]);
    $paths = [$upload->storage_path, 'exports/'.$transcript->id.'/file.pdf', 'translations/'.$translation->id.'/file.docx', 'dubbings/'.$dub->id.'/video.mp4'];
    foreach ($paths as $path) {
        Storage::disk('r2')->put($path, 'private');
    }
    $response = $this->postJson('/api/v1/privacy/deletions', ['resource_type' => 'folder', 'resource_id' => $folder->id, 'scope' => 'project'])->assertAccepted();
    $this->getJson('/api/v1/folders/'.$folder->id)->assertNotFound();
    expect(Transcription::find($transcript->id))->toBeNull()->and(Translation::find($translation->id))->toBeNull()->and(Dubbing::find($dub->id))->toBeNull();
    Storage::disk('r2')->assertExists($paths);
    expect(app(PrivacyRepositoryInterface::class)->sourceDeletionPending($upload->storage_path))->toBeTrue();
    expect(app(ProcessPrivacyDeletion::class)->handle($response->json('id')))->toBeTrue();
    Storage::disk('r2')->assertMissing($paths);
    expect(PrivacyDeletion::find($response->json('id'))->status)->toBe('completed');
    expect(UploadSession::find($upload->id))->toBeNull();
    expect(Transcription::withTrashed()->find($transcript->id)->transcript)->toBeNull();
    $this->assertDatabaseCount('folders', 0);
});

test('folder deletion preserves other folders and their shared source files', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Remove']);
    $other = Folder::create(['user_id' => $this->user->id, 'name' => 'Keep']);
    $upload = folderDeletionUpload($this->user);
    $deleted = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path]);
    $kept = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path]);
    $folder->transcriptions()->attach($deleted->id);
    $other->transcriptions()->attach($kept->id);
    Storage::disk('r2')->put($upload->storage_path, 'shared');
    $this->deleteJson('/api/v1/folders/'.$folder->id)->assertNoContent();
    $deletion = PrivacyDeletion::where('resource_type', 'folder')->sole();
    app(ProcessPrivacyDeletion::class)->handle($deletion->id);
    Storage::disk('r2')->assertExists($upload->storage_path);
    expect(app(PrivacyRepositoryInterface::class)->sourceDeletionPending($upload->storage_path))->toBeFalse();
    expect($kept->fresh())->not->toBeNull()->and($other->fresh())->not->toBeNull();
    expect(Transcription::find($deleted->id))->toBeNull();
    expect($kept->fresh()->audio_storage_path)->toBe($upload->storage_path);
});

test('folder deletion rejects other owners and duplicate requests reuse the saved cleanup', function () {
    $foreign = Folder::create(['user_id' => User::factory()->create()->id, 'name' => 'Private']);
    $this->deleteJson('/api/v1/folders/'.$foreign->id)->assertNotFound();
    $this->postJson('/api/v1/privacy/deletions', ['resource_type' => 'folder', 'resource_id' => $foreign->id, 'scope' => 'project'])->assertNotFound();
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Own']);
    $input = ['resource_type' => 'folder', 'resource_id' => $folder->id, 'scope' => 'project'];
    $first = $this->postJson('/api/v1/privacy/deletions', $input)->assertAccepted()->json('id');
    $this->postJson('/api/v1/privacy/deletions', $input)->assertAccepted()->assertJsonPath('id', $first);
    $this->assertDatabaseCount('privacy_deletions', 1);
    expect($foreign->fresh())->not->toBeNull();
});

test('failed folder cleanup remains retryable without recreating or repeating completed child cleanups', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Remove']);
    Translation::factory()->create(['user_id' => $this->user->id, 'folder_id' => $folder->id]);
    $record = app(PrivacyService::class)->delete($this->user->id, 'folder', $folder->id, 'project');
    $storage = Mockery::mock(PrivacyStorageInterface::class);
    $storage->shouldReceive('purge')->once()->andThrow(new RuntimeException('Storage unavailable'));
    app()->instance(PrivacyStorageInterface::class, $storage);
    expect(fn () => app(ProcessPrivacyDeletion::class)->handle($record['id']))->toThrow(RuntimeException::class);
    expect(PrivacyDeletion::find($record['id'])->status)->toBe('failed');
    app(PrivacyService::class)->retry($record['id'], $this->user->id);
    app()->forgetInstance(PrivacyStorageInterface::class);
    app(ProcessPrivacyDeletion::class)->handle($record['id']);
    expect(PrivacyDeletion::find($record['id'])->status)->toBe('completed');
    $this->assertDatabaseCount('privacy_deletions', 2);
});

test('folder cleanup does not rerun completed children when a later storage purge fails', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Remove']);
    Translation::factory()->count(2)->create(['user_id' => $this->user->id, 'folder_id' => $folder->id]);
    $deletion = app(PrivacyService::class)->delete($this->user->id, 'folder', $folder->id, 'project');
    $storage = Mockery::mock(PrivacyStorageInterface::class);
    $storage->shouldReceive('purge')->once()->ordered()->andReturnNull();
    $storage->shouldReceive('purge')->once()->ordered()->andThrow(new RuntimeException('Second file failed'));
    $storage->shouldReceive('purge')->once()->ordered()->andReturnNull();
    app()->instance(PrivacyStorageInterface::class, $storage);
    expect(fn () => app(ProcessPrivacyDeletion::class)->handle($deletion['id']))->toThrow(RuntimeException::class);
    $children = PrivacyDeletion::where('resource_type', 'translation')->orderBy('id')->get();
    expect($children->pluck('status')->all())->toBe(['completed', 'failed']);
    app(PrivacyService::class)->retry($deletion['id'], $this->user->id);
    expect(app(ProcessPrivacyDeletion::class)->handle($deletion['id']))->toBeTrue();
    expect($children[0]->fresh()->attempts)->toBe(1)->and($children[1]->fresh()->attempts)->toBe(2);
});

test('folder deletion prevents late transcription callbacks from restoring removed content', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Remove']);
    $transcript = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
    $folder->transcriptions()->attach($transcript->id);
    $this->deleteJson('/api/v1/folders/'.$folder->id)->assertNoContent();
    app(TranscriptionCompletion::class)
        ->complete($transcript->id, 'deepgram', 'late-request', 'Should not return', []);
    app()->call([new SubmitTranscription($transcript->id, 'en'), 'handle']);
    expect(Transcription::find($transcript->id))->toBeNull();
    expect(OutboxEvent::where('event_type', 'TranscriptionCompleted')->exists())->toBeFalse();
});
