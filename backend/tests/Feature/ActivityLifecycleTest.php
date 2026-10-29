<?php

use App\Domain\Billing\Services\ActivityLifecycleService;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingGatewayInterface;
use App\Domain\Dubbing\Contracts\DubbingGatewayResolverInterface;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\ProcessDubbing;
use App\Domain\Transcriber\Contracts\TranscriptToolGatewayInterface;
use App\Domain\Transcriber\Services\ProcessTranscriptTool;
use App\Domain\Translation\Contracts\TranslationGatewayInterface;
use App\Domain\Translation\Contracts\TranslationGatewayResolverInterface;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.free_credits' => '0']);
    Queue::fake();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'lifecycle-test');
});

function lifecycleTask($test, string $type): mixed
{
    $record = match ($type) {
        'transcription' => Transcription::factory()->create(['user_id' => $test->user->id, 'status' => 'pending', 'provider' => 'deepgram', 'provider_request_id' => (string) Str::uuid()]),
        'translation' => Translation::factory()->create(['user_id' => $test->user->id, 'status' => 'pending', 'translated_text' => null]),
        'dubbing' => Dubbing::factory()->create(['user_id' => $test->user->id, 'status' => 'pending', 'provider_project_id' => null, 'submission_started_at' => null, 'provider_completed_at' => null]),
        'transcript_tool' => TranscriptTool::factory()->create(['user_id' => $test->user->id, 'status' => 'pending', 'result' => null]),
    };
    $record->refresh();
    $activity = match ($type) {
        'transcript_tool' => 'cleanup', default => $type
    };
    $quote = BillingQuote::create(['user_id' => $test->user->id, 'client_key' => (string) Str::uuid(), 'activity' => $activity,
        'provider' => $record->provider, 'model' => 'test', 'quantity' => 60000, 'credit_units' => 100,
        'rate' => ['credit_units' => 100, 'unit_length' => 60000], 'source' => [], 'request_source' => [], 'status' => 'ready', 'expires_at' => now()->addHour()]);
    app(CreditService::class)->reserve($test->user->id, $quote->toArray(), $record->id);

    return $record;
}

test('owners can cancel active tasks once and recover their reserved credits', function (string $type) {
    $record = lifecycleTask($this, $type);
    $event = app(OutboxService::class)->record('lifecycle:'.$record->id, 'TestRequested', $record->id, []);

    $this->postJson('/api/v1/activities/'.$type.'/'.$record->id.'/cancel')->assertOk()->assertJsonPath('status', 'failed');
    $this->postJson('/api/v1/activities/'.$type.'/'.$record->id.'/cancel')->assertOk()->assertJsonPath('status', 'failed');

    expect($record->refresh()->cancelled_at)->not->toBeNull();
    expect($record->failure_reason)->toStartWith('Cancelled by you.');
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0]);
    expect($event->refresh()->published_at)->not->toBeNull();
    $this->assertDatabaseHas('usage_charges', ['status' => 'released', match ($type) {
        'transcription' => 'transcription_id', 'translation' => 'translation_id', 'dubbing' => 'dubbing_id', 'transcript_tool' => 'transcript_tool_id'
    } => $record->id]);
    Http::assertNothingSent();
})->with(['transcription', 'translation', 'dubbing', 'transcript_tool']);

test('cancellation is account scoped and cannot cancel completed paid work', function () {
    $record = lifecycleTask($this, 'transcription');
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/activities/transcription/'.$record->id.'/cancel')->assertNotFound();
    expect($record->refresh()->status)->toBe('pending');
    Sanctum::actingAs($this->user);
    app(CreditService::class)->consume($record->id);
    $record->update(['status' => 'complete']);
    $this->postJson('/api/v1/activities/transcription/'.$record->id.'/cancel')->assertOk()->assertJsonPath('status', 'complete')->assertJsonPath('cancelledAt', null);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 9900, 'reserved_units' => 0]);
});

test('late deepgram callbacks cannot revive cancelled transcriptions or consume returned credits', function () {
    $record = lifecycleTask($this, 'transcription');
    $this->postJson('/api/v1/activities/transcription/'.$record->id.'/cancel')->assertOk();
    $url = URL::temporarySignedRoute('deepgram.callback', now()->addHour(), ['transcription' => $record->id], absolute: false);
    $this->postJson($url, ['metadata' => ['request_id' => $record->provider_request_id],
        'results' => ['channels' => [['alternatives' => [['transcript' => 'A late result']]]]]])->assertNoContent();
    expect($record->refresh()->status)->toBe('failed');
    expect($record->transcript)->not->toBe('A late result');
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0]);
    expect(OutboxEvent::where('event_type', 'TranscriptionCompleted')->count())->toBe(0);
});

test('signed callbacks are ignored when cancellation preceded saving the provider request id', function () {
    $record = lifecycleTask($this, 'transcription');
    $record->update(['provider_request_id' => null]);
    $this->postJson('/api/v1/activities/transcription/'.$record->id.'/cancel')->assertOk();
    $url = URL::temporarySignedRoute('deepgram.callback', now()->addHour(), ['transcription' => $record->id], absolute: false);
    $this->postJson($url, ['metadata' => ['request_id' => 'accepted-after-cancellation'],
        'results' => ['channels' => [['alternatives' => [['transcript' => 'Late result']]]]]])->assertNoContent();
    expect($record->refresh()->status)->toBe('failed');
    expect($record->provider_request_id)->toBeNull();
});

test('watchdog uses fixed start time and gives longer recordings additional time', function () {
    config(['billing.task_deadlines.transcription.deepgram' => ['base_minutes' => 1, 'duration_multiplier' => 1, 'max_minutes' => 120]]);
    $short = lifecycleTask($this, 'transcription');
    $long = lifecycleTask($this, 'transcription');
    $short->forceFill(['duration' => 60, 'activity_started_at' => now()->subMinutes(10), 'updated_at' => now()])->save();
    $long->forceFill(['duration' => 3600, 'activity_started_at' => now()->subMinutes(10), 'updated_at' => now()])->save();

    $this->artisan('activities:recover')->assertSuccessful();
    $this->artisan('activities:recover')->assertSuccessful();

    expect($short->refresh()->status)->toBe('failed');
    expect($short->cancelled_at)->toBeNull();
    expect($short->failure_reason)->toStartWith('Processing exceeded its time limit.');
    expect($long->refresh()->status)->toBe('pending');
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 9900, 'reserved_units' => 100]);
});

test('translation results arriving during cancellation cannot be saved or exported', function () {
    $record = lifecycleTask($this, 'translation');
    $gateway = Mockery::mock(TranslationGatewayInterface::class);
    $gateway->shouldReceive('translate')->once()->andReturnUsing(function () use ($record) {
        app(ActivityLifecycleService::class)->cancel('translation', $record->id, $this->user->id);

        return ['texts' => ['Late translation'], 'detected_language' => 'en'];
    });
    $resolver = Mockery::mock(TranslationGatewayResolverInterface::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($gateway);
    $this->app->instance(TranslationGatewayResolverInterface::class, $resolver);

    app(ProcessTranslation::class)->handle($record->id);

    expect($record->refresh()->status)->toBe('failed');
    expect($record->translated_text)->toBeNull();
    expect(app(CreditService::class)->balance($this->user->id)['reserved_units'])->toBe(0);
});

test('cleanup arriving during cancellation cannot complete or charge the task', function () {
    $record = lifecycleTask($this, 'transcript_tool');
    $gateway = Mockery::mock(TranscriptToolGatewayInterface::class);
    $gateway->shouldReceive('generate')->once()->andReturnUsing(function () use ($record) {
        app(ActivityLifecycleService::class)->cancel('transcript_tool', $record->id, $this->user->id);

        return ['text' => 'Late cleanup', 'segments' => []];
    });
    $this->app->instance(TranscriptToolGatewayInterface::class, $gateway);

    app(ProcessTranscriptTool::class)->handle($record->id);

    expect($record->refresh()->status)->toBe('failed');
    expect($record->result)->toBeNull();
    expect(app(CreditService::class)->balance($this->user->id)['reserved_units'])->toBe(0);
});

test('dubbing submission returning after cancellation cannot restart processing', function () {
    $record = lifecycleTask($this, 'dubbing');
    $media = Mockery::mock(DubbingMediaInterface::class);
    $media->shouldReceive('sourceUrl')->once()->andReturn('https://example.test/video.mp4');
    $this->app->instance(DubbingMediaInterface::class, $media);
    $gateway = Mockery::mock(DubbingGatewayInterface::class);
    $gateway->shouldReceive('create')->once()->andReturnUsing(function () use ($record) {
        app(ActivityLifecycleService::class)->cancel('dubbing', $record->id, $this->user->id);

        return ['project_id' => 'late-project', 'language_ids' => ['late-language']];
    });
    $resolver = Mockery::mock(DubbingGatewayResolverInterface::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($gateway);
    $this->app->instance(DubbingGatewayResolverInterface::class, $resolver);

    app(ProcessDubbing::class)->handle($record->id);

    expect($record->refresh()->status)->toBe('failed');
    expect($record->provider_project_id)->toBeNull();
    expect(app(CreditService::class)->balance($this->user->id)['reserved_units'])->toBe(0);
});
