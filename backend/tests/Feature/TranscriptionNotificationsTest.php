<?php

use App\Domain\Transcriber\Events\TranscriptionCompleted;
use App\Domain\Transcriber\Events\TranscriptionFailed;
use App\Domain\Transcriber\Services\FinalizeTranscriptionExports;
use App\Infrastructure\Notifications\SendTranscriptionOutcomeEmail;
use App\Infrastructure\Notifications\TranscriptionOutcomePublisher;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Jobs\GenerateTranscriptionExports;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use App\Notifications\TranscriptionOutcomeNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(DatabaseMigrations::class);

test('completion emails link to the owners folder and repeated deliveries are skipped', function () {
    Notification::fake();
    config(['app.frontend_url' => 'https://teetranscribe.example']);
    $user = User::factory()->create();
    $folder = Folder::query()->create(['user_id' => $user->id, 'name' => 'Interviews']);
    $record = Transcription::factory()->create(['user_id' => $user->id, 'provider' => 'intron', 'status' => 'complete']);
    $folder->transcriptions()->attach($record->id);
    $listener = app(SendTranscriptionOutcomeEmail::class);
    $listener->handle(new TranscriptionCompleted($record->id));
    $listener->handle(new TranscriptionCompleted($record->id));
    Notification::assertSentToTimes($user, TranscriptionOutcomeNotification::class, 1);
    Notification::assertSentTo($user, TranscriptionOutcomeNotification::class, function ($notification) use ($user, $folder) {
        $mail = $notification->toMail($user);

        return $mail->subject === 'Transcription complete'
            && $mail->actionUrl === 'https://teetranscribe.example/dashboard/transcriptions/'.$folder->id;
    });
    expect($record->refresh()->completion_notified_at)->not->toBeNull();
});

test('failure emails are deduplicated and stale failure events are skipped', function () {
    Notification::fake();
    $user = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $user->id, 'status' => 'failed']);
    $listener = app(SendTranscriptionOutcomeEmail::class);
    $listener->handle(new TranscriptionFailed($record->id));
    $listener->handle(new TranscriptionFailed($record->id));
    Notification::assertSentToTimes($user, TranscriptionOutcomeNotification::class, 1);
    Notification::assertSentTo($user, TranscriptionOutcomeNotification::class,
        fn ($notification) => $notification->toMail($user)->subject === 'Transcription failed');
    Notification::fake();
    $record->update(['status' => 'complete']);
    $listener->handle(new TranscriptionFailed($record->id));
    Notification::assertNothingSent();
});

test('guest outcomes do not send email', function () {
    Notification::fake();
    $record = Transcription::factory()->create(['user_id' => null, 'status' => 'complete']);
    app(SendTranscriptionOutcomeEmail::class)->handle(new TranscriptionCompleted($record->id));
    Notification::assertNothingSent();
});

test('completion is emitted once after all exports are ready', function () {
    Event::fake([TranscriptionCompleted::class]);
    $record = Transcription::factory()->create(['status' => 'processing']);
    TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => 'txt', 'status' => 'completed', 'storage_path' => 'exports/test.txt']);
    $finalizer = app(FinalizeTranscriptionExports::class);
    $finalizer->handle($record->id);
    Event::assertNotDispatched(TranscriptionCompleted::class);
    TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => 'pdf', 'status' => 'completed', 'storage_path' => 'exports/test.pdf']);
    $finalizer->handle($record->id);
    Event::assertNotDispatched(TranscriptionCompleted::class);
    TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => 'docx', 'status' => 'completed', 'storage_path' => 'exports/test.docx']);
    $finalizer->handle($record->id);
    $finalizer->handle($record->id);
    Event::assertDispatchedTimes(TranscriptionCompleted::class, 1);
});

test('rollback prevents outcome events from being published', function () {
    Event::fake([TranscriptionFailed::class]);
    $record = Transcription::factory()->create(['status' => 'pending']);
    DB::beginTransaction();
    app(TranscriptionOutcomePublisher::class)->failed($record->id);
    DB::rollBack();
    Event::assertNotDispatched(TranscriptionFailed::class);
    expect($record->refresh()->status)->toBe('pending');
});

test('exhausted submission and export jobs publish failure events', function () {
    Event::fake([TranscriptionFailed::class]);
    $record = Transcription::factory()->create(['status' => 'pending']);
    (new SubmitTranscription($record->id, 'en'))->failed(new RuntimeException('Provider unavailable'));
    expect($record->refresh()->status)->toBe('failed');
    Event::assertDispatched(TranscriptionFailed::class, fn ($event) => $event->transcriptionId === $record->id);
    $other = Transcription::factory()->create(['status' => 'processing']);
    (new GenerateTranscriptionExports($other->id))->failed(new RuntimeException('Export unavailable'));
    expect($other->refresh()->status)->toBe('failed');
    Event::assertDispatched(TranscriptionFailed::class, fn ($event) => $event->transcriptionId === $other->id);
});

test('outcome events queue the email listener', function () {
    Queue::fake();
    Event::dispatch(new TranscriptionCompleted('test-id'));
    Queue::assertPushedOn('default', CallQueuedListener::class,
        fn ($job) => $job->class === SendTranscriptionOutcomeEmail::class);
});

test('mail transport failures leave the notification available for retry', function () {
    $user = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $user->id, 'status' => 'complete']);
    $listener = app(SendTranscriptionOutcomeEmail::class);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Mail server unavailable'));
    expect(fn () => $listener->handle(new TranscriptionCompleted($record->id)))
        ->toThrow(RuntimeException::class, 'Mail server unavailable');
    expect($record->refresh()->completion_notified_at)->toBeNull();
    Notification::fake();
    $listener->handle(new TranscriptionCompleted($record->id));
    Notification::assertSentToTimes($user, TranscriptionOutcomeNotification::class, 1);
    expect($record->refresh()->completion_notified_at)->not->toBeNull();
});
