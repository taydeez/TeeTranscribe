<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\Exports\TranscriptionExportGenerator;
use App\Infrastructure\Outbox\OutboxService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptTool;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\GenerateTranscriptionExports;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['billing.free_credits' => '0', 'transcriber.deepgram.key' => 'test', 'transcriber.deepgram.model' => 'nova-2']);
    Bus::fake();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'seed');
});

function concurrencyQuote(User $user): BillingQuote
{
    return BillingQuote::create(['user_id' => $user->id, 'client_key' => (string) Str::uuid(), 'activity' => 'transcription',
        'provider' => 'deepgram', 'model' => 'nova-2', 'quantity' => 60000, 'credit_units' => 100,
        'rate' => ['credit_units' => 100, 'unit_length' => 60000],
        'source' => ['audio_url' => 'https://storage.example.com/recording.mp3', 'language_code' => 'en'],
        'request_source' => ['audio_url' => 'https://storage.example.com/recording.mp3', 'language_code' => 'en'],
        'status' => 'ready', 'expires_at' => now()->addHour()]);
}

test('transcription admission rejects excess work without spending credits or creating queue entries', function () {
    $quotes = collect(range(1, 3))->map(fn () => concurrencyQuote($this->user));
    $ids = [];
    foreach ($quotes->take(2) as $quote) {
        $ids[] = $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertAccepted()->json('id');
    }
    $events = DB::table('outbox_events')->count();
    Cache::store('array')->flush();
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quotes[2]->id])->assertStatus(429)->assertJsonPath('message', 'You have reached your transcription limit (2). Wait for a task to finish before starting another.');
    expect(Transcription::count())->toBe(2)->and(UsageCharge::count())->toBe(2)
        ->and(DB::table('outbox_events')->count())->toBe($events)->and($quotes[2]->refresh()->status)->toBe('ready');
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 9800)->assertJsonPath('reserved_units', 200);
    // Repeating an accepted request returns the same task, even at capacity.
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quotes[0]->id])->assertAccepted()->assertJsonPath('id', $ids[0]);
    $this->assertDatabaseCount('usage_charges', 2);
});

test('provider completion keeps its slot while exports process and terminal outcomes free capacity', function (string $outcome) {
    $first = concurrencyQuote($this->user);
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $first->id])->assertAccepted()->json('id');
    app(CreditService::class)->consume($id);
    Transcription::whereKey($id)->update(['status' => 'processing', 'transcript' => 'Saved result']);
    Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
    $next = concurrencyQuote($this->user);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $next->id])->assertStatus(429);
    Transcription::whereKey($id)->update(['status' => $outcome]);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $next->id])->assertAccepted();
})->with(['complete', 'failed']);

test('the overall limit combines activities and is isolated per customer', function () {
    Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
    Translation::factory()->create(['user_id' => $this->user->id, 'status' => 'processing']);
    Dubbing::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
    $quote = concurrencyQuote($this->user);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertStatus(429)->assertJsonFragment(['message' => 'You already have 3 tasks queued or processing. Wait for one to finish before starting another.']);
    $other = User::factory()->create();
    app(CreditService::class)->purchase($other->id, 1000, 'other');
    Sanctum::actingAs($other);
    $this->postJson('/api/v1/transcribe', ['quote_id' => concurrencyQuote($other)->id])->assertAccepted();
});

test('all activity limits use active task status and summaries share capacity with cleanup', function (string $activity, string $model, int $limit) {
    for ($i = 0; $i < $limit; $i++) {
        $model::factory()->create(['user_id' => $this->user->id, 'status' => $i % 2 ? 'processing' : 'pending']);
    }
    expect(fn () => DB::transaction(fn () => app(CreditService::class)->assertConcurrency($this->user->id, $activity, 'new')))
        ->toThrow(BillingException::class);
})->with([
    ['transcription', Transcription::class, 2], ['translation', Translation::class, 3],
    ['dubbing', Dubbing::class, 1], ['summary', TranscriptTool::class, 2], ['cleanup', TranscriptTool::class, 2],
]);

test('editing cannot bypass admission and an existing processing task does not acquire another slot', function () {
    Transcription::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => 'processing']);
    $record = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'complete', 'transcript' => 'Original']);
    $this->patchJson('/api/v1/transcriptions/'.$record->id, ['transcript' => 'Edited'])->assertStatus(429);
    expect($record->refresh()->transcript)->toBe('Original')->and($record->status)->toBe('complete');
    $active = Transcription::where('status', 'processing')->firstOrFail();
    $this->patchJson('/api/v1/transcriptions/'.$active->id, ['transcript' => 'Corrected again'])->assertOk();
    expect(Transcription::where('status', 'processing')->count())->toBe(2);
});

test('capacity cannot be bypassed if Redis is unavailable', function () {
    config(['billing.concurrency_store' => 'unavailable']);
    $quote = concurrencyQuote($this->user);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertStatus(503);
    $this->assertDatabaseCount('transcriptions', 0);
    $this->assertDatabaseCount('usage_charges', 0);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 10000);
});

test('a competing admission lock rejects safely and a rolled back admission leaves no slot', function () {
    $lock = Cache::store('array')->lock('activities:admission:'.$this->user->id, 15);
    expect($lock->get())->toBeTrue();
    $quote = concurrencyQuote($this->user);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertStatus(503);
    $lock->release();
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertAccepted();
    $this->assertDatabaseCount('transcriptions', 1);
    $this->assertDatabaseCount('usage_charges', 1);
});

test('stalled reserved tasks return credits and consumed export tasks also release capacity', function () {
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => concurrencyQuote($this->user)->id])->assertAccepted()->json('id');
    $consumed = $this->postJson('/api/v1/transcribe', ['quote_id' => concurrencyQuote($this->user)->id])->assertAccepted()->json('id');
    app(CreditService::class)->consume($consumed);
    Transcription::whereKey($consumed)->update(['status' => 'processing', 'transcript' => 'Saved result']);
    DB::table('transcriptions')->whereIn('id', [$id, $consumed])->update(['updated_at' => now()->subHours(27)]);
    $this->artisan('activities:recover')->assertSuccessful();
    $this->artisan('activities:recover')->assertSuccessful();
    expect(Transcription::whereIn('id', [$id, $consumed])->pluck('status')->all())->toBe(['failed', 'failed']);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('available_units', 9900)->assertJsonPath('reserved_units', 0);
    $this->postJson('/api/v1/transcribe', ['quote_id' => concurrencyQuote($this->user)->id])->assertAccepted();
});

test('recovery leaves recent and terminal work untouched', function () {
    $recent = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'processing']);
    $done = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'complete', 'updated_at' => now()->subHours(27)]);
    $this->artisan('activities:recover')->assertSuccessful();
    expect($recent->refresh()->status)->toBe('processing')->and($done->refresh()->status)->toBe('complete');
});

test('transient export failures hold capacity until retries are exhausted and stale deliveries stay terminal', function () {
    $record = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'processing', 'transcript' => 'Saved text']);
    Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'pending']);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('r2')->andReturn($disk);
    expect(fn () => app(TranscriptionExportGenerator::class)->generate($record->id, 'txt', 0))->toThrow(RuntimeException::class);
    expect($record->refresh()->status)->toBe('processing');
    $quote = concurrencyQuote($this->user);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertStatus(429);
    $job = new GenerateTranscriptionExports($record->id);
    $job->failed(new RuntimeException('Retries exhausted'));
    expect($record->refresh()->status)->toBe('failed');
    $job->handle(app(OutboxService::class));
    expect($record->refresh()->status)->toBe('failed');
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote->id])->assertAccepted();
});

test('recovery also frees stalled translation dubbing and transcript tool capacity', function () {
    $translation = Translation::factory()->create(['user_id' => $this->user->id, 'status' => 'processing', 'translated_text' => 'Saved result', 'updated_at' => now()->subHours(27)]);
    $dubbing = Dubbing::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'updated_at' => now()->subHours(27)]);
    $tool = TranscriptTool::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'updated_at' => now()->subHours(27)]);
    $this->artisan('activities:recover')->assertSuccessful();
    expect($translation->refresh()->status)->toBe('failed')->and($dubbing->refresh()->status)->toBe('failed')->and($tool->refresh()->status)->toBe('failed');
    // A late translation delivery must not restart rendering after recovery freed its slot.
    app(ProcessTranslation::class)->handle($translation->id);
    expect($translation->refresh()->status)->toBe('failed');
    $this->postJson('/api/v1/transcribe', ['quote_id' => concurrencyQuote($this->user)->id])->assertAccepted();
});
