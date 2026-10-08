<?php

use App\Infrastructure\Exports\TranscriptionExportGenerator;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GenerateTranscriptionExports;
use App\Jobs\RegenerateTranscriptionExports;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('the coordinator uploads both formats before acknowledging the outbox event', function () {
    Storage::fake('r2');
    $transcription = Transcription::factory()->create([
        'name' => 'Customer Interview',
        'status' => 'processing',
        'transcript' => 'The completed transcript.',
    ]);
    $event = app(OutboxService::class)->record(
        "transcription:{$transcription->id}:completed",
        'TranscriptionCompleted',
        $transcription->id,
        ['transcription_id' => $transcription->id],
    );

    (new GenerateTranscriptionExports($transcription->id, $event->id))
        ->handle(app(OutboxService::class));

    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'txt',
        'status' => 'completed',
    ]);
    $this->assertDatabaseHas('transcription_exports', [
        'transcription_id' => $transcription->id,
        'format' => 'pdf',
        'status' => 'completed',
    ]);
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Customer Interview.txt");
    Storage::disk('r2')->assertExists("exports/{$transcription->id}/Customer Interview.pdf");
    expect($transcription->refresh()->status)->toBe('complete')
        ->and($event->refresh()->published_at)->not->toBeNull();
});

test('publishing queues one coordinator without acknowledging the event early', function () {
    Queue::fake();
    $transcription = Transcription::factory()->create([
        'status' => 'processing',
        'transcript' => 'Ready to export.',
    ]);
    $event = OutboxEvent::factory()->create([
        'aggregate_id' => $transcription->id,
        'event_type' => 'TranscriptionCompleted',
        'attempts' => 0,
        'published_at' => null,
    ]);

    $this->artisan('outbox:publish')->assertSuccessful();

    expect($event->refresh()->attempts)->toBe(1)
        ->and($event->published_at)->toBeNull();
    Queue::assertPushed(GenerateTranscriptionExports::class, fn ($job): bool => $job->transcriptionId === $transcription->id
        && $job->outboxEventId === $event->id);
});

test('publishing recovers a prematurely acknowledged incomplete export', function () {
    Queue::fake();
    $transcription = Transcription::factory()->create([
        'status' => 'processing',
        'transcript' => 'Ready to export.',
    ]);
    $event = OutboxEvent::factory()->create([
        'aggregate_id' => $transcription->id,
        'event_type' => 'TranscriptionCompleted',
        'attempts' => 1,
        'published_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('outbox:publish')->assertSuccessful();

    expect($event->refresh()->attempts)->toBe(2)
        ->and($event->published_at)->toBeNull();
    Queue::assertPushed(GenerateTranscriptionExports::class);
});

test('regeneration publishes only the latest revision for both formats', function () {
    Storage::fake('r2');
    $record = Transcription::factory()->create(['status' => 'processing', 'transcript' => 'Latest edited text.', 'name' => 'Interview', 'export_revision' => 2]);
    foreach (['txt', 'pdf'] as $format) {
        TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => $format, 'status' => 'pending', 'storage_path' => null, 'export_revision' => 2]);
    }
    $generator = app(TranscriptionExportGenerator::class);
    $generator->generate($record->id, 'txt', 1);
    expect(Storage::disk('r2')->allFiles())->toBe([]);
    (new RegenerateTranscriptionExports($record->id))->handle($generator);
    expect($record->refresh()->status)->toBe('complete');
    expect(Storage::disk('r2')->get("exports/{$record->id}/revisions/2/Interview.txt"))->toBe('Latest edited text.');
    Storage::disk('r2')->assertExists("exports/{$record->id}/revisions/2/Interview.pdf");
    expect($record->exports()->pluck('export_revision')->all())->toBe([2, 2, 2]);
});

test('an edit arriving during upload prevents an old export from being published', function () {
    $record = Transcription::factory()->create(['status' => 'processing', 'transcript' => 'Old revision.', 'name' => 'Interview', 'export_revision' => 1]);
    $disk = Mockery::mock(FilesystemAdapter::class);
    Storage::shouldReceive('disk')->with('r2')->andReturn($disk);
    $disk->shouldReceive('put')->once()->andReturnUsing(function () use ($record): bool {
        $record->update(['transcript' => 'New revision.', 'export_revision' => 2]);
        $record->exports()->update(['export_revision' => 2, 'status' => 'pending', 'storage_path' => null]);

        return true;
    });
    $disk->shouldReceive('exists')->once()->andReturn(true);
    $disk->shouldReceive('size')->once()->andReturn(strlen('Old revision.'));
    app(TranscriptionExportGenerator::class)->generate($record->id, 'txt', 1);
    $export = $record->exports()->sole();
    expect($export->status)->toBe('pending')->and($export->storage_path)->toBeNull()
        ->and($export->export_revision)->toBe(2)->and($record->refresh()->status)->toBe('processing');
    app(TranscriptionExportGenerator::class)->failed($record->id, 1);
    expect($record->refresh()->status)->toBe('processing');
});
