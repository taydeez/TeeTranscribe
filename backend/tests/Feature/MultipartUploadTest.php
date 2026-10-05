<?php

use App\Domain\Upload\Contracts\MultipartStorageInterface;
use App\Domain\Upload\Enums\UploadStatus;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\FakeMultipartStorage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->storage = new FakeMultipartStorage;
    $this->app->instance(MultipartStorageInterface::class, $this->storage);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->metadata = [
        'client_key' => 'e7b4909b-1570-4cde-8a13-b3d8a428f2a2',
        'filename' => 'interview.mp3', 'content_type' => 'audio/mpeg',
        'size' => 20 * 1024 * 1024, 'fingerprint' => str_repeat('a', 64),
    ];
});

function startMultipart(object $test): string
{
    return $test->postJson('/api/v1/uploads/multipart', $test->metadata)->assertOk()->json('id');
}

test('starts an owned large upload without proxying file bytes', function (): void {
    $this->metadata['size'] = 5 * 1024 * 1024 * 1024;
    $id = startMultipart($this);
    $this->assertDatabaseHas('upload_sessions', [
        'id' => $id, 'user_id' => $this->user->id, 'size' => $this->metadata['size'], 'status' => 'uploading',
    ]);
    expect($id)->toMatch('/^[0-9A-Z]{26}$/')
        ->and($this->storage->begins)->toBe(1);
    $this->postJson("/api/v1/uploads/multipart/{$id}/part", ['part_number' => 1])
        ->assertOk()->assertJsonStructure(['upload_url', 'headers']);
});

test('multipart endpoints require authentication', function (): void {
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/uploads/multipart', $this->metadata)->assertUnauthorized();
});

test('cleanup preserves completed objects even if their database status was not updated', function (): void {
    $id = startMultipart($this);
    $this->storage->objects[$id] = true;
    UploadSession::whereKey($id)->update(['expires_at' => now()->subDay()]);
    $this->artisan('uploads:cleanup')->assertSuccessful();
    expect($this->storage->aborts)->toBe(0);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'completed']);
});

test('recovers a lost start response with the same client key', function (): void {
    $id = startMultipart($this);
    $this->postJson('/api/v1/uploads/multipart', $this->metadata)->assertOk()->assertJsonPath('id', $id);
    expect($this->storage->begins)->toBe(1);
    $this->assertDatabaseCount('upload_sessions', 1);
});

test('cannot reuse an upload session for another file', function (): void {
    startMultipart($this);
    $this->metadata['fingerprint'] = str_repeat('b', 64);
    $this->postJson('/api/v1/uploads/multipart', $this->metadata)->assertConflict();
    expect($this->storage->begins)->toBe(1);
});

test('reconciles uploaded parts from storage after an interruption', function (): void {
    $id = startMultipart($this);
    $this->storage->uploadedParts[$id] = [['number' => 1, 'size' => 16 * 1024 * 1024, 'etag' => '"part-one"']];
    $this->postJson("/api/v1/uploads/multipart/{$id}/status")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('parts.0.number', 1)->assertJsonPath('part_count', 2);
    $this->postJson("/api/v1/uploads/multipart/{$id}/part", ['part_number' => 2])->assertOk();
    expect($this->storage->begins)->toBe(1);
});

test('another user cannot inspect sign complete or abort an upload', function (): void {
    $id = startMultipart($this);
    Sanctum::actingAs(User::factory()->create());
    foreach (['status', 'part', 'complete', 'abort'] as $action) {
        $this->postJson("/api/v1/uploads/multipart/{$id}/{$action}", ['part_number' => 1])->assertNotFound();
    }
    expect($this->storage->completions)->toBe(0)->and($this->storage->aborts)->toBe(0);
});

test('refuses completion until every correctly sized part exists', function (): void {
    $id = startMultipart($this);
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")->assertConflict();
    $this->storage->uploadedParts[$id] = [
        ['number' => 1, 'size' => 16 * 1024 * 1024, 'etag' => '"one"'],
        ['number' => 2, 'size' => 1, 'etag' => '"two"'],
    ];
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")->assertConflict();
    expect($this->storage->completions)->toBe(0);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'uploading']);
});

test('verifies the uploaded object and completion is idempotent', function (): void {
    $id = startMultipart($this);
    $this->storage->uploadedParts[$id] = [
        ['number' => 2, 'size' => 4 * 1024 * 1024, 'etag' => '"two"'],
        ['number' => 1, 'size' => 16 * 1024 * 1024, 'etag' => '"one"'],
    ];
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")
        ->assertOk()->assertJsonPath('audio_storage_path', 'audio/'.$id.'.mp3');
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")->assertOk();
    expect($this->storage->completions)->toBe(1);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'completed']);
    $this->postJson("/api/v1/uploads/multipart/{$id}/part", ['part_number' => 1])->assertConflict();
});

test('recovers when storage completed but its response was lost', function (): void {
    $id = startMultipart($this);
    $this->storage->uploadedParts[$id] = [
        ['number' => 1, 'size' => 16 * 1024 * 1024, 'etag' => '"one"'],
        ['number' => 2, 'size' => 4 * 1024 * 1024, 'etag' => '"two"'],
    ];
    $this->storage->failAfterCompletion = true;
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")->assertStatus(500);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'uploading']);
    $this->postJson("/api/v1/uploads/multipart/{$id}/complete")->assertOk();
    expect($this->storage->completions)->toBe(1);
});

test('cancels unfinished uploads once and refuses further upload parts', function (): void {
    $id = startMultipart($this);
    $this->postJson("/api/v1/uploads/multipart/{$id}/abort")->assertOk();
    $this->postJson("/api/v1/uploads/multipart/{$id}/abort")->assertOk();
    $this->postJson("/api/v1/uploads/multipart/{$id}/part", ['part_number' => 1])->assertGone();
    expect($this->storage->aborts)->toBe(1);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'aborted']);
});

test('completed objects are protected from cancellation', function (): void {
    $id = startMultipart($this);
    $this->storage->objects[$id] = true;
    $this->postJson("/api/v1/uploads/multipart/{$id}/abort")->assertConflict();
    expect($this->storage->aborts)->toBe(0);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => 'completed']);
});

test('expired sessions cannot resume and cleanup aborts their unfinished parts', function (): void {
    $id = startMultipart($this);
    UploadSession::whereKey($id)->update(['expires_at' => now()->subDay()]);
    $this->postJson("/api/v1/uploads/multipart/{$id}/status")->assertGone();
    $this->artisan('uploads:cleanup')->assertSuccessful();
    expect($this->storage->aborts)->toBe(1);
    $this->assertDatabaseHas('upload_sessions', ['id' => $id, 'status' => UploadStatus::Aborted->value]);
});
