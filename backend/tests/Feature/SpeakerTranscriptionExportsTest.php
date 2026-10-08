<?php

use App\Infrastructure\Exports\TranscriptionExportGenerator;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GenerateTranscriptionExports;
use App\Jobs\RegenerateTranscriptionExports;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function speakerExportSegments(): array
{
    return [
        ['start' => 0, 'end' => 1, 'speaker' => 'Ada', 'text' => 'Hello & welcome.', 'confidence' => 0.9],
        ['start' => 1, 'end' => 2, 'speaker' => 'Ada', 'text' => 'Mò ń test.', 'confidence' => 0.9],
        ['start' => 2, 'end' => 3, 'speaker' => 'Speaker 2', 'text' => 'Thank you <Ada>.', 'confidence' => 0.9],
    ];
}

test('speaker transcripts produce six verified files before the outbox is acknowledged', function () {
    Storage::fake('r2');
    $record = Transcription::factory()->create(['status' => 'processing', 'name' => 'Interview', 'transcript' => 'Plain combined text.', 'segments' => speakerExportSegments()]);
    $event = app(OutboxService::class)->record('test:'.$record->id, 'TranscriptionCompleted', $record->id, ['transcription_id' => $record->id]);
    (new GenerateTranscriptionExports($record->id, $event->id))->handle(app(OutboxService::class));
    expect($record->refresh()->status)->toBe('complete')->and($record->exports()->count())->toBe(6)->and($event->refresh()->published_at)->not->toBeNull();
    expect(Storage::disk('r2')->get("exports/{$record->id}/Interview.txt"))->toBe('Plain combined text.');
    expect(Storage::disk('r2')->get("exports/{$record->id}/speakers/Interview.txt"))->toBe("Ada:\nHello & welcome. Mò ń test.\n\nSpeaker 2:\nThank you <Ada>.");
    foreach (['plain', 'speakers'] as $variant) {
        foreach (['txt', 'pdf', 'docx'] as $format) {
            $export = $record->exports()->where('format', $format)->where('variant', $variant)->sole();
            expect($export->status)->toBe('completed');
            Storage::disk('r2')->assertExists($export->storage_path);
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'docx-test-');
    try {
        file_put_contents($path, Storage::disk('r2')->get("exports/{$record->id}/speakers/Interview.docx"));
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $xml = new DOMDocument;
        expect($xml->loadXML($zip->getFromName('word/document.xml')))->toBeTrue();
        expect($xml->textContent)->toContain('Ada:', 'Speaker 2:', 'Hello & welcome.', 'Mò ń test.', 'Thank you <Ada>.');
        expect($zip->getFromName('[Content_Types].xml'))->toContain('wordprocessingml.document.main+xml');
        expect($zip->getFromName('_rels/.rels'))->toContain('Target="word/document.xml"');
        $zip->close();
    } finally {
        unlink($path);
    }
    Queue::fake();
    Sanctum::actingAs($record->user);
    $this->postJson("/api/v1/transcriptions/{$record->id}/exports")->assertOk()->assertJsonPath('status', 'complete');
    Queue::assertNothingPushed();
});

test('an older owned transcript can prepare new formats without changing its text or revision', function () {
    Queue::fake();
    $record = Transcription::factory()->create(['status' => 'complete', 'transcript' => 'Original.', 'segments' => speakerExportSegments()]);
    Sanctum::actingAs($record->user);
    foreach (['txt', 'pdf'] as $format) {
        TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => $format, 'status' => 'completed', 'storage_path' => 'old.'.$format]);
    }
    $this->postJson("/api/v1/transcriptions/{$record->id}/exports")->assertAccepted()->assertJsonPath('status', 'processing');
    $this->postJson("/api/v1/transcriptions/{$record->id}/exports")->assertAccepted();
    expect($record->refresh()->transcript)->toBe('Original.')->and($record->export_revision)->toBe(0)->and($record->exports()->count())->toBe(6);
    Queue::assertPushed(RegenerateTranscriptionExports::class, 1);
});

test('export preparation requires authentication and ownership', function () {
    Queue::fake();
    $record = Transcription::factory()->create(['status' => 'complete']);
    $this->postJson("/api/v1/transcriptions/{$record->id}/exports")->assertUnauthorized();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson("/api/v1/transcriptions/{$record->id}/exports")->assertNotFound();
    Queue::assertNothingPushed();
});

test('DOCX upload failure prevents completion and can be retried without duplicating successful exports', function () {
    Storage::fake('r2');
    $originalDisk = Storage::disk('r2');
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->withArgs(fn ($path, $bytes, $options) => ! str_ends_with($path, '.docx'))->andReturnUsing(fn ($path, $bytes, $options) => $originalDisk->put($path, $bytes, $options));
    $disk->shouldReceive('exists')->andReturnUsing(fn ($path) => $originalDisk->exists($path));
    $disk->shouldReceive('size')->andReturnUsing(fn ($path) => $originalDisk->size($path));
    $disk->shouldReceive('put')->withArgs(fn ($path, $bytes, $options) => str_ends_with($path, '.docx'))->once()->andReturnFalse();
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
    $record = Transcription::factory()->create(['status' => 'processing', 'transcript' => 'Ready.', 'segments' => []]);
    $outbox = app(OutboxService::class);
    $event = $outbox->record('test:'.$record->id, 'TranscriptionCompleted', $record->id, ['transcription_id' => $record->id]);
    expect(fn () => (new GenerateTranscriptionExports($record->id, $event->id))->handle($outbox))->toThrow(RuntimeException::class, 'Failed to upload DOCX');
    expect($record->refresh()->status)->toBe('failed')->and($event->refresh()->published_at)->toBeNull();
    $disk->shouldReceive('put')->withArgs(fn ($path, $bytes, $options) => str_ends_with($path, '.docx'))->once()->andReturnUsing(fn ($path, $bytes, $options) => $originalDisk->put($path, $bytes, $options));
    (new GenerateTranscriptionExports($record->id, $event->id))->handle($outbox);
    expect($record->refresh()->status)->toBe('complete')->and($record->exports()->count())->toBe(3)->and($event->refresh()->published_at)->not->toBeNull();
});

test('edited speaker names and text regenerate every format from the latest revision', function () {
    Storage::fake('r2');
    Queue::fake();
    $record = Transcription::factory()->create(['status' => 'complete', 'provider' => 'deepgram', 'name' => 'Interview', 'transcript' => 'Original.', 'segments' => speakerExportSegments()]);
    Sanctum::actingAs($record->user);
    $this->patchJson("/api/v1/transcriptions/{$record->id}", ['transcript' => 'Ignored', 'segments' => [
        ['text' => 'Edited.', 'speaker' => 'Bola'], ['text' => 'Still Bola.', 'speaker' => 'Bola'], ['text' => 'Reply.', 'speaker' => 'Chidi'],
    ]])->assertOk();
    (new RegenerateTranscriptionExports($record->id))->handle(app(TranscriptionExportGenerator::class));
    expect($record->refresh()->status)->toBe('complete')->and($record->exports()->count())->toBe(6);
    expect(Storage::disk('r2')->get("exports/{$record->id}/revisions/1/speakers/Interview.txt"))->toBe("Bola:\nEdited. Still Bola.\n\nChidi:\nReply.");
});
