<?php

use App\Domain\Transcriber\Entities\TranscriptionExport as ExportEntity;
use App\Domain\Transcriber\Exceptions\TranscriptionExportNotFoundException;
use App\Domain\Transcriber\Services\TranscriptionExportService;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

test('creates a pending export and returns a domain entity', function () {
    $transcription = Transcription::factory()->create();

    $export = app(TranscriptionExportService::class)->create([
        'transcription_id' => $transcription->id, 'format' => 'pdf',
    ]);

    expect($export)->toBeInstanceOf(ExportEntity::class);
    expect(Str::isUlid($export->id))->toBeTrue();
    expect($export->status)->toBe('pending');
    expect($export->storagePath)->toBeNull();
    $this->assertDatabaseHas('transcription_exports', [
        'id' => $export->id, 'transcription_id' => $transcription->id, 'format' => 'pdf', 'status' => 'pending',
    ]);
    expect($transcription->exports->modelKeys())->toBe([$export->id]);
});

test('lists only the requested transcription exports and finds exports by id', function () {
    $transcription = Transcription::factory()->create();
    $txt = TranscriptionExport::factory()->for($transcription)->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
    $pdf = TranscriptionExport::factory()->for($transcription)->create(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'format' => 'pdf']);
    $other = TranscriptionExport::factory()->create();
    $service = app(TranscriptionExportService::class);

    $exports = $service->forTranscription($transcription->id);

    expect(array_column($exports, 'id'))->toBe([$pdf->id, $txt->id]);
    expect($service->findOrFail($txt->id)->transcriptionId)->toBe($transcription->id);
    expect($service->find('01ARZ3NDEKTSV4RRFFQ69G5FAX'))->toBeNull();
    expect(array_column($service->all(), 'id'))->toContain($other->id, $txt->id, $pdf->id);
});

test('updates export processing details and clears nullable fields', function () {
    $this->travelTo(now()->startOfSecond());
    $model = TranscriptionExport::factory()->create();
    $service = app(TranscriptionExportService::class);

    $processing = $service->update($model->id, ['status' => 'pending', 'processing_started_at' => now()]);
    expect($processing->processingStartedAt)->toEqual(now()->toDateTimeImmutable());
    $failed = $service->update($model->id, ['status' => 'failed', 'failure_reason' => 'Storage unavailable']);
    expect($failed->failureReason)->toBe('Storage unavailable');
    $completed = $service->update($model->id, [
        'status' => 'completed', 'storage_path' => 'exports/result.txt',
        'failure_reason' => null, 'processing_started_at' => null,
    ]);

    expect($completed->status)->toBe('completed');
    expect($completed->failureReason)->toBeNull();
    expect($completed->processingStartedAt)->toBeNull();
    $this->assertDatabaseHas('transcription_exports', [
        'id' => $model->id, 'status' => 'completed', 'storage_path' => 'exports/result.txt',
        'failure_reason' => null, 'processing_started_at' => null, 'format' => 'txt',
    ]);
});

test('deletes only the selected export', function () {
    $export = TranscriptionExport::factory()->create();
    $other = TranscriptionExport::factory()->create();

    expect(app(TranscriptionExportService::class)->delete($export->id))->toBeTrue();

    $this->assertModelMissing($export);
    $this->assertModelExists($other);
});

test('rejects duplicate formats for the same transcription', function () {
    $export = TranscriptionExport::factory()->create();

    expect(fn () => app(TranscriptionExportService::class)->create([
        'transcription_id' => $export->transcription_id, 'format' => $export->format,
    ]))->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('transcription_exports', 1);
});

test('rejects changes to a missing export', function (string $operation) {
    $service = app(TranscriptionExportService::class);
    $id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    expect(fn () => $operation === 'update'
        ? $service->update($id, ['status' => 'failed'])
        : $service->delete($id))->toThrow(TranscriptionExportNotFoundException::class);

    $this->assertDatabaseCount('transcription_exports', 0);
})->with(['update', 'delete']);
