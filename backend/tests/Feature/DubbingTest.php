<?php

use App\Domain\Billing\Services\CreditService;
use App\Domain\Dubbing\Contracts\DubbingMediaInterface;
use App\Domain\Dubbing\Services\DubbingService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\Dubbing;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['dubbing.key' => 'test-key', 'billing.free_credits' => '0', 'billing.rates.dubbing.elevenlabs.dubbing_v2.credits' => '10']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->upload = UploadSession::create(['user_id' => $this->user->id, 'client_key' => (string) Str::uuid(), 'filename' => 'Interview.mp4',
        'content_type' => 'video/mp4', 'size' => 1000, 'fingerprint' => str_repeat('a', 64), 'storage_path' => 'audio/'.Str::ulid().'.mp4',
        'part_size' => 16 * 1024 * 1024, 'expires_at' => now()->addDays(7), 'status' => 'completed']);
    $this->mock(DubbingMediaInterface::class, function ($mock) {
        $mock->shouldReceive('inspect')->andReturn(['size' => 1000, 'duration_ms' => 90500]);
    });
    $this->input = ['client_key' => (string) Str::uuid(), 'video_storage_path' => $this->upload->storage_path, 'source_language' => 'en', 'target_language' => 'yo', 'name' => 'Yoruba interview'];
});
test('dubbing exposes global languages with supported Nigerian languages first', function () {
    $response = $this->getJson('/api/v1/dubbings/languages')->assertOk()->assertJsonPath('configured', true);
    $codes = array_column($response->json('data'), 'code');
    expect(array_slice($codes, 0, 3))->toBe(['yo', 'ha', 'en'])->and($codes)->toContain('ja', 'fr', 'ar-EG', 'es-MX')->not->toContain('ig', 'pcm');
});
test('measured dubbing quote confirms idempotently and reserves the correct duration price once', function () {
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->assertJsonPath('status', 'measuring')->json();
    expect(Dubbing::count())->toBe(0);
    $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->assertJsonPath('id', $quote['id']);
    app(DubbingService::class)->measure($quote['id']);
    $this->getJson('/api/v1/dubbings/quotes/'.$quote['id'])->assertOk()->assertJsonPath('credit_units', 1509)->assertJsonPath('quantity', 90500)->assertJsonPath('enough_credits', true);
    $first = $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('id', $first['id']);
    expect(Dubbing::count())->toBe(1)->and(UsageCharge::count())->toBe(1)
        ->and(UsageCharge::sole()->dubbing_id)->toBe($first['id'])->and(UsageCharge::sole()->transcription_id)->toBeNull()
        ->and(CreditWallet::sole()->reserved_units)->toBe(1509)->and(CreditWallet::sole()->available_units)->toBe(8491)
        ->and(OutboxEvent::where('event_type', 'DubbingRequested')->count())->toBe(1);
    expect($first)->not->toHaveKey('provider_project_id')->not->toHaveKey('source_storage_path');
    Http::assertNothingSent();
});
test('dubbing cannot expose another users videos uploads or quotes', function () {
    $other = User::factory()->create();
    $record = Dubbing::factory()->create(['user_id' => $other->id]);
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    Sanctum::actingAs($other);
    $this->getJson('/api/v1/dubbings/quotes/'.$quote['id'])->assertNotFound();
    $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertNotFound();
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/dubbings/'.$record->id)->assertNotFound();
    $this->postJson('/api/v1/dubbings/'.$record->id.'/retry')->assertNotFound();
    $this->getJson('/api/v1/dubbings')->assertOk()->assertJsonCount(0, 'data');
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/dubbings/languages')->assertUnauthorized();
    $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertUnauthorized();
});
test('insufficient credits roll back confirmation and expired quotes do not start work', function () {
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    CreditWallet::where('user_id', $this->user->id)->update(['available_units' => 0]);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertStatus(402);
    expect(Dubbing::count())->toBe(0)->and(UsageCharge::count())->toBe(0);
    BillingQuote::whereKey($quote['id'])->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/dubbings', ['quote_id' => $quote['id']])->assertUnprocessable();
});
test('owned dubbing history is paginated and creating an audio quote cannot bypass dubbing billing', function () {
    Dubbing::factory()->count(3)->create(['user_id' => $this->user->id]);
    $this->getJson('/api/v1/dubbings?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    $quote = $this->postJson('/api/v1/dubbings/quotes', $this->input)->assertAccepted()->json();
    app(DubbingService::class)->measure($quote['id']);
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertUnprocessable();
});
