<?php

use App\Domain\Auth\Contracts\AuthRepositoryInterface;
use App\Infrastructure\Observability\ObserveJob;
use App\Infrastructure\Observability\OperationalLogger;
use App\Infrastructure\Observability\TimedMediaProcess;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Jobs\SubmitTranscription;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function () {
    config(['observability.enabled' => true]);
    Http::preventStrayRequests();
});

test('application exceptions and provider messages are stored without private response content', function () {
    Log::error('A request failed.', ['exception' => new RuntimeException('Token secret-token https://private.example/audio?signature=hidden'), 'transcript' => 'private words', 'password' => 'private password']);
    app(OperationalLogger::class)->scope = ['transcription_id' => 'transcription-one'];
    Http::fake(['api.deepgram.com/*' => Http::response(['message' => 'Quota exhausted', 'code' => 'QUOTA', 'transcript' => 'secret transcript', 'request_id' => 'request-one'], 429)]);
    Http::withToken('secret-key')->post('https://api.deepgram.com/v1/listen?callback=https://secret.example', ['url' => 'https://source.example/private']);
    $row = DB::table('operational_logs')->where('message', 'Provider HTTP response received.')->first();
    expect($row->provider)->toBe('deepgram')->and($row->operation_id)->toBe('transcription-one')
        ->and($row->elapsed_ms)->not->toBeNull()->and($row->context)->toContain('Quota exhausted')->toContain('429')->not->toContain('secret transcript');
    $stored = DB::table('operational_logs')->get()->toJson();
    expect($stored)->not->toContain('secret-key')->not->toContain('secret-token')->not->toContain('private words')->not->toContain('private password')->not->toContain('signature=hidden');
});

test('job attempts expose reasons and timings and restore scope even on failure', function () {
    $job = new SubmitTranscription('transcription-one', 'en');
    $logger = app(OperationalLogger::class);
    $logger->scope = ['request_id' => 'outer'];
    expect(fn () => (new ObserveJob)->handle($job, fn () => throw new RuntimeException('Audio could not be decoded.')))->toThrow(RuntimeException::class);
    $row = DB::table('operational_logs')->where('message', 'Job attempt threw an exception.')->first();
    expect($row->operation_id)->toBe('transcription-one')->and($row->context)->toContain('Audio could not be decoded.')
        ->and($row->elapsed_ms)->not->toBeNull()->and($logger->scope)->toBe(['request_id' => 'outer']);
    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('uuid')->andReturn('queue-one');
    $queueJob->shouldReceive('resolveName')->andReturn(SubmitTranscription::class);
    $queueJob->shouldReceive('getQueue')->andReturn('ingestion');
    $queueJob->shouldReceive('attempts')->andReturn(3);
    Event::dispatch(new JobFailed('redis', $queueJob, new RuntimeException('Attempts exhausted.')));
    expect(DB::table('operational_logs')->where('job_id', 'queue-one')->value('context'))->toContain('Attempts exhausted.')->toContain('"attempt":3');
});

test('ffmpeg and ffprobe timings preserve process success and failure results', function () {
    Process::fake(['ffmpeg *' => Process::result(errorOutput: 'Decoder failed', exitCode: 1), 'ffprobe *' => Process::result(output: '{}')]);
    expect(TimedMediaProcess::run(['ffmpeg', '-v', 'error'], 30)->failed())->toBeTrue();
    expect(TimedMediaProcess::run(['ffprobe', '-v', 'error'], 30)->successful())->toBeTrue();
    $rows = DB::table('operational_logs')->where('type', 'media')->get();
    expect($rows)->toHaveCount(2)->and($rows[0]->level)->toBe('error')->and($rows[0]->context)->toContain('Decoder failed')
        ->and($rows[0]->elapsed_ms)->not->toBeNull()->and($rows[1]->level)->toBe('info');
});

test('lifecycle logs record committed outcomes and total duration but ignore rolled back changes', function () {
    $record = Transcription::factory()->create(['status' => 'pending', 'duration' => 60]);
    $this->travel(10)->seconds();
    $record->update(['status' => 'processing']);
    $this->travel(5)->seconds();
    $record->update(['status' => 'complete']);
    $row = DB::table('operational_logs')->where('type', 'lifecycle')->where('operation_id', $record->id)->orderByDesc('created_at')->first();
    expect($row->context)->toContain('complete')->toContain('duration_seconds')->and((int) $row->elapsed_ms)->toBeGreaterThanOrEqual(15000);
    expect($row->message)->toBe('Operation state changed.');
    $count = DB::table('operational_logs')->count();
    try {
        DB::transaction(function () use ($record) {
            $record->update(['status' => 'failed']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect(DB::table('operational_logs')->count())->toBe($count);
});

test('provider connection failures record elapsed time without leaking credentials', function () {
    Http::fake(['api.deepgram.com/*' => Http::failedConnection()]);
    try {
        Http::withToken('hidden-api-key')->get('https://api.deepgram.com/v1/listen');
    } catch (ConnectionException) {
    }
    $row = DB::table('operational_logs')->where('message', 'Provider HTTP connection failed.')->first();
    expect($row)->not->toBeNull()->and($row->elapsed_ms)->not->toBeNull()->and($row->context)->not->toContain('hidden-api-key');
});

test('HTTP exception logs omit response bodies while retaining the safe provider message', function () {
    Http::fake(['api.deepgram.com/*' => Http::response(['message' => 'Quota exceeded', 'transcript' => 'Private provider text'], 429)]);
    try {
        Http::post('https://api.deepgram.com/v1/listen')->throw();
    } catch (RequestException $exception) {
        Log::error('Provider call failed', ['exception' => $exception]);
    }
    expect(DB::table('operational_logs')->get()->toJson())->toContain('Quota exceeded')->not->toContain('Private provider text');
});

test('log storage failures and disabled logging never break work', function () {
    config(['observability.enabled' => false]);
    app(OperationalLogger::class)->record('application', 'error', 'Disabled');
    expect(DB::table('operational_logs')->count())->toBe(0);
    config(['observability.enabled' => true]);
    Schema::drop('operational_logs');
    app(OperationalLogger::class)->record('application', 'error', 'Storage outage');
    expect(app(OperationalLogger::class)->elapsedSinceSubmission('one'))->toBeNull();
});

test('admin logs are permission scoped filterable paginated and expose details separately', function () {
    $this->seed(PermissionsSeeder::class);
    $this->getJson('/api/v1/admin/logs')->assertUnauthorized();
    $user = User::factory()->create();
    $this->withToken(app(AuthRepositoryInterface::class)->issueToken($user->id, 'web'))->getJson('/api/v1/admin/logs')->assertForbidden();
    app('auth')->forgetGuards();
    $admin = User::factory()->create()->assignRole('admin');
    $token = app(AuthRepositoryInterface::class)->issueToken($admin->id, 'admin');
    $this->withToken($token)->getJson('/api/v1/admin/logs')->assertForbidden();
    $admin->givePermissionTo('ViewAny_OperationalLog');
    app('auth')->forgetGuards();
    app(OperationalLogger::class)->record('provider', 'warning', 'Provider quota exhausted', ['provider' => 'deepgram', 'transcription_id' => 'one']);
    app(OperationalLogger::class)->record('media', 'info', 'Finished', ['provider' => 'openai']);
    $id = DB::table('operational_logs')->where('type', 'provider')->value('id');
    $this->getJson('/api/v1/admin/logs?type=provider&level=warning&provider=deepgram&operation_id=one&per_page=1&search=quota')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.context')->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/v1/admin/logs/'.$id)->assertForbidden();
    $admin->givePermissionTo('View_OperationalLog');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/admin/logs/'.$id)->assertOk()->assertJsonPath('data.context.provider', 'deepgram');
    $this->getJson('/api/v1/admin/logs/'.Str::ulid())->assertNotFound();
});

test('expired operational logs are pruned without removing recent logs', function () {
    app(OperationalLogger::class)->record('job', 'info', 'Old');
    DB::table('operational_logs')->update(['created_at' => now()->subDays(31)]);
    app(OperationalLogger::class)->record('job', 'info', 'Recent');
    $this->artisan('logs:prune')->assertSuccessful();
    expect(DB::table('operational_logs')->pluck('message')->all())->toBe(['Recent']);
});

test('verified callbacks measure provider turnaround and duplicates do not complete twice', function () {
    $record = Transcription::factory()->create(['provider' => 'deepgram', 'provider_request_id' => 'timed-request', 'status' => 'pending']);
    app(OperationalLogger::class)->record('provider', 'info', 'Transcription submitted to provider.', ['transcription_id' => $record->id, 'provider' => 'deepgram']);
    $this->travel(12)->seconds();
    $url = URL::signedRoute('deepgram.callback', ['transcription' => $record->id], absolute: false);
    $payload = ['metadata' => ['request_id' => 'timed-request', 'duration' => 7],
        'results' => ['channels' => [['alternatives' => [['transcript' => 'Private spoken words']]]]]];
    $this->postJson($url, $payload)->assertNoContent();
    $callback = DB::table('operational_logs')->where('message', 'Verified Deepgram callback received.')->first();
    expect((int) $callback->elapsed_ms)->toBeGreaterThanOrEqual(12000)->and($callback->provider_request_id)->toBe('timed-request');
    $this->postJson($url, $payload)->assertNoContent();
    expect(DB::table('operational_logs')->where('message', 'Verified Deepgram callback received.')->count())->toBe(1)
        ->and(DB::table('operational_logs')->where('message', 'Duplicate Deepgram callback ignored.')->count())->toBe(1)
        ->and(DB::table('operational_logs')->get()->toJson())->not->toContain('Private spoken words');
});
