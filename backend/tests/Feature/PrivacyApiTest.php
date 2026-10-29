<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Domain\Privacy\Services\ProcessPrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\PrivacyDeletion;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\TranscriptionExport;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Jobs\ProcessPrivacyDeletion as CleanupJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('r2');
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function privacyUpload(User $user, string $kind = 'audio'): UploadSession
{
    $id = (string) Str::ulid();

    return UploadSession::create(['id' => $id, 'user_id' => $user->id, 'client_key' => (string) Str::uuid(),
        'filename' => 'interview.mp3', 'content_type' => 'audio/mpeg', 'size' => 1234, 'fingerprint' => str_repeat('a', 64),
        'storage_path' => 'audio/'.$id.'.mp3', 'part_size' => 5242880, 'expires_at' => now()->addDay(),
        'status' => 'completed', 'source_kind' => $kind]);
}

function privacyRequest($test, string $type, string $id, string $scope, ?string $category = null): array
{
    return $test->postJson('/api/v1/privacy/deletions', ['resource_type' => $type, 'resource_id' => $id,
        'scope' => $scope, ...($category === null ? [] : ['category' => $category])])->assertAccepted()->json();
}

test('retention defaults keep every category and partial updates are private to the account', function () {
    $response = $this->getJson('/api/v1/privacy/settings')->assertOk()->assertJsonPath('cleanupIntervalMinutes', 15);
    expect(array_filter($response->json('retention')))->toBe([]);
    $this->patchJson('/api/v1/privacy/settings', ['retention' => ['pdf' => 24, 'recordings' => 1]])
        ->assertOk()->assertJsonPath('retention.pdf', 24)->assertJsonPath('retention.txt', null);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/privacy/settings')->assertOk()->assertJsonPath('retention.pdf', null);
});

test('privacy ownership covers sources projects manifests and retries', function () {
    $other = User::factory()->create();
    $record = Transcription::factory()->create(['user_id' => $other->id]);
    $this->postJson('/api/v1/privacy/deletions', ['resource_type' => 'transcription', 'resource_id' => $record->id, 'scope' => 'project'])->assertNotFound();
    Sanctum::actingAs($other);
    $deletion = privacyRequest($this, 'transcription', $record->id, 'project');
    expect($deletion)->not->toHaveKey('payload')->not->toHaveKey('userId');
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/privacy/deletions/'.$deletion['id'])->assertNotFound();
    $this->postJson('/api/v1/privacy/deletions/'.$deletion['id'].'/retry')->assertNotFound();
    $this->getJson('/api/v1/privacy/deletions')->assertJsonPath('data', []);
});

test('source deletion waits for active work then removes a shared source without losing transcripts', function () {
    $upload = privacyUpload($this->user);
    $first = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path,
        'status' => 'processing', 'transcript' => 'Saved words']);
    $second = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path, 'status' => 'complete']);
    Storage::disk('r2')->put($upload->storage_path, 'source audio');
    $deletion = privacyRequest($this, 'upload', $upload->id, 'source');
    expect(app(ProcessPrivacyDeletion::class)->handle($deletion['id']))->toBeFalse();
    Storage::disk('r2')->assertExists($upload->storage_path);
    $first->update(['status' => 'complete']);
    expect(app(ProcessPrivacyDeletion::class)->handle($deletion['id']))->toBeTrue();
    Storage::disk('r2')->assertMissing($upload->storage_path);
    expect($first->refresh()->transcript)->toBe('Saved words')->and($first->source_deleted_at)->not->toBeNull()
        ->and($second->refresh()->source_deleted_at)->not->toBeNull();
    $this->getJson('/api/v1/privacy/files')->assertOk()->assertJsonPath('meta.total', 0);
});

test('project deletion hides immediately purges previous revisions and preserves independent shared uploads', function () {
    $upload = privacyUpload($this->user);
    $record = Transcription::factory()->create(['user_id' => $this->user->id, 'audio_storage_path' => $upload->storage_path,
        'status' => 'complete', 'transcript' => 'Private content']);
    $source = $upload->storage_path;
    $old = 'exports/'.$record->id.'/revisions/1/old.txt';
    $cache = 'transcription-inputs/'.$record->id.'/openai-result.enc';
    Storage::disk('r2')->put($source, 'audio');
    Storage::disk('r2')->put($old, 'private export');
    Storage::disk('r2')->put($cache, 'private cache');
    $deletion = privacyRequest($this, 'transcription', $record->id, 'project');
    expect(Transcription::find($record->id))->toBeNull();
    expect(privacyRequest($this, 'transcription', $record->id, 'project')['id'])->toBe($deletion['id']);
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    Storage::disk('r2')->assertMissing([$old, $cache]);
    Storage::disk('r2')->assertExists($source);
    expect(Transcription::withTrashed()->find($record->id)->transcript)->toBeNull();
    $this->getJson('/api/v1/privacy/deletions/'.$deletion['id'])->assertJsonPath('status', 'completed');
    Queue::assertPushed(CleanupJob::class);
});

test('format deletion removes all variants and revisions while keeping other formats and text', function () {
    $record = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'complete', 'transcript' => 'Keep my text']);
    $pdf = 'exports/'.$record->id.'/revisions/1/speakers/private.pdf';
    $txt = 'exports/'.$record->id.'/private.txt';
    Storage::disk('r2')->put($pdf, 'pdf');
    Storage::disk('r2')->put($txt, 'txt');
    TranscriptionExport::factory()->create(['transcription_id' => $record->id, 'format' => 'pdf', 'status' => 'completed', 'storage_path' => $pdf]);
    $deletion = privacyRequest($this, 'transcription', $record->id, 'generated', 'pdf');
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    Storage::disk('r2')->assertMissing($pdf);
    Storage::disk('r2')->assertExists($txt);
    expect($record->refresh()->transcript)->toBe('Keep my text')->and($record->exports()->count())->toBe(0);
});

test('cleanup uses each account policy and does not delete while scanning', function () {
    $old = Translation::factory()->create(['user_id' => $this->user->id, 'status' => 'complete', 'created_at' => now()->subHours(3)]);
    $kept = Translation::factory()->create(['status' => 'complete', 'created_at' => now()->subHours(3)]);
    $this->patchJson('/api/v1/privacy/settings', ['retention' => ['translations' => 2]])->assertOk();
    $this->artisan('privacy:cleanup')->assertExitCode(0);
    expect(Translation::find($old->id))->toBeNull()->and(Translation::find($kept->id))->not->toBeNull();
    expect(PrivacyDeletion::where('resource_id', $old->id)->count())->toBe(1);
    Queue::assertPushed(CleanupJob::class);
    $this->artisan('privacy:cleanup')->assertExitCode(0);
    expect(PrivacyDeletion::count())->toBe(1);
});

test('deleting a project returns its unspent AI tool reservation exactly once', function () {
    config(['billing.free_credits' => '0', 'openai.key' => 'test-key',
        'billing.rates.summary.openai' => ['gpt-4.1-mini' => ['unit' => '1000_characters', 'credits' => '20', 'provider_cost' => '']]]);
    app(CreditService::class)->purchase($this->user->id, 10000, 'privacy-tool-test');
    $record = Transcription::factory()->create(['user_id' => $this->user->id, 'status' => 'complete', 'transcript' => 'Private transcript to summarize']);
    $quote = $this->postJson('/api/v1/transcriptions/'.$record->id.'/tools/quotes', ['operation' => 'summary', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $this->postJson('/api/v1/transcriptions/'.$record->id.'/tools', ['quote_id' => $quote['id']])->assertAccepted();
    expect(app(CreditService::class)->balance($this->user->id)['reserved_units'])->toBeGreaterThan(0);
    $deletion = privacyRequest($this, 'transcription', $record->id, 'project');
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    app(ProcessPrivacyDeletion::class)->handle($deletion['id']);
    expect(app(CreditService::class)->balance($this->user->id))->toMatchArray(['available_units' => 10000, 'reserved_units' => 0]);
    $this->assertDatabaseCount('transcript_tools', 0);
    Http::assertNothingSent();
});

test('sources pending deletion cannot be used in new paid quotes', function () {
    $upload = privacyUpload($this->user);
    privacyRequest($this, 'upload', $upload->id, 'source');
    expect(fn () => app(UsageQuoteService::class)->create($this->user->id,
        ['audio_storage_path' => $upload->storage_path, 'audio_url' => 'https://example.com/source.mp3', 'language_code' => 'en'], (string) Str::uuid()))
        ->toThrow(BillingException::class, 'This source file is unavailable or being deleted.');
    $this->assertDatabaseCount('billing_quotes', 0);
});
