<?php

use App\Domain\Billing\Contracts\MediaDurationInspectorInterface;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Billing\Services\UsageQuoteService;
use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    config(['billing.rates.transcription.deepgram.nova-2.credits' => '10', 'billing.free_credits' => '0', 'transcriber.deepgram.model' => 'nova-2', 'transcriber.fallback' => 'deepgram']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-credit');
    $this->mock(MediaDurationInspectorInterface::class)->shouldReceive('measure')->andReturn([
        'duration_ms' => 60000, 'audio_url' => 'https://storage.example.com/interview.mp3',
        'audio_storage_path' => 'billing-media/verified', 'file_name' => 'interview.mp3',
    ]);
    $this->quote = app(UsageQuoteService::class)->create($this->user->id, ['audio_url' => 'https://example.com/interview.mp3', 'language_code' => 'en'], (string) Str::uuid());
    app(UsageQuoteService::class)->measure($this->quote['id']);
});

test('owns the transcription from the quote regardless of submitted identity', function () {
    $other = User::factory()->create();
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $this->quote['id'], 'user_id' => $other->id, 'guest_session_id' => (string) Str::uuid()])->assertAccepted()->json('id');
    $record = Transcription::findOrFail($id);
    expect($record->user_id)->toBe($this->user->id)->and($record->guest_session_id)->toBeNull();
    expect($record->folders()->sole()->name)->toBe(now()->format('F j, Y'));
});

test('attaches a paid transcription to the selected owned folder', function () {
    $folder = Folder::create(['user_id' => $this->user->id, 'name' => 'Interviews']);
    $id = $this->postJson('/api/v1/transcribe', ['quote_id' => $this->quote['id'], 'folder_id' => $folder->id])->assertAccepted()->json('id');
    expect($folder->transcriptions()->sole()->id)->toBe($id);
});

test('rejects another users folder without reserving credits', function () {
    $folder = Folder::create(['user_id' => User::factory()->create()->id, 'name' => 'Private']);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $this->quote['id'], 'folder_id' => $folder->id])->assertNotFound();
    $this->assertDatabaseCount('transcriptions', 0);
    $this->getJson('/api/v1/billing/balance')->assertJsonPath('reserved_units', 0);
});
