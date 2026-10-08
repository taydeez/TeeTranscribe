<?php

use App\Domain\Billing\Services\CreditService;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Cache::forget('translation:google:nmt:languages:en');
    config(['translation.google.key' => 'test-key', 'billing.free_credits' => '0', 'billing.rates.translation.google.nmt.credits' => '10']);
    $this->user = User::factory()->create();
    Http::fake(['translation.googleapis.com/language/translate/v2/languages*' => Http::response(['data' => ['languages' => [
        ['language' => 'fr', 'name' => 'French'], ['language' => 'en', 'name' => 'English'], ['language' => 'ja', 'name' => 'Japanese'],
        ['language' => 'yo', 'name' => 'Yoruba'], ['language' => 'ig', 'name' => 'Igbo'], ['language' => 'ha', 'name' => 'Hausa'],
        ['language' => 'es', 'name' => 'Spanish'], ['language' => 'zh-CN', 'name' => 'Chinese (Simplified)'],
    ]]])]);
});

test('translation endpoints require authentication', function () {
    $this->getJson('/api/v1/translations/languages')->assertUnauthorized();
    $this->getJson('/api/v1/translations')->assertUnauthorized();
    $this->postJson('/api/v1/translations/quotes')->assertUnauthorized();
    $this->postJson('/api/v1/translations')->assertUnauthorized();
    Http::assertNothingSent();
});

test('the global language catalog highlights Nigerian languages first and is cached', function () {
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/translations/languages')->assertOk()->assertJsonCount(8, 'data')
        ->assertJsonPath('data.0.code', 'yo')->assertJsonPath('data.0.nigerian', true)
        ->assertJsonPath('data.1.code', 'ig')->assertJsonPath('data.2.code', 'ha')->assertJsonPath('data.3.code', 'en')
        ->assertJsonFragment(['code' => 'ja', 'name' => 'Japanese', 'nigerian' => false]);
    $this->getJson('/api/v1/translations/languages')->assertOk();
    Http::assertSentCount(1);
});

test('quotes count Unicode characters and repeated confirmation reserves credits once', function () {
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $text = 'Mò ń test. 👋';
    $key = (string) Str::uuid();
    $body = ['text' => $text, 'source_language' => 'yo', 'target_language' => 'fr', 'client_key' => $key];
    $quote = $this->postJson('/api/v1/translations/quotes', $body)->assertOk()->assertJsonPath('quantity', mb_strlen($text, 'UTF-8'))->json();
    $this->postJson('/api/v1/translations/quotes', $body)->assertOk()->assertJsonPath('id', $quote['id']);
    $first = $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('id', $first['id']);
    expect(Translation::count())->toBe(1)->and(UsageCharge::where('activity', 'translation')->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'TranslationSubmitted')->count())->toBe(1);
    $wallet = CreditWallet::where('user_id', $this->user->id)->sole();
    expect($wallet->reserved_units)->toBe($quote['credit_units'])->and($wallet->available_units)->toBe(10000 - $quote['credit_units']);
    expect($first)->not->toHaveKey('provider');
});

test('insufficient credits roll back the translation and outbox while leaving the quote reusable', function () {
    Sanctum::actingAs($this->user);
    $quote = $this->postJson('/api/v1/translations/quotes', ['text' => 'Hello.', 'target_language' => 'fr', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertStatus(402);
    expect(Translation::count())->toBe(0)->and(OutboxEvent::where('event_type', 'TranslationSubmitted')->count())->toBe(0)
        ->and(BillingQuote::find($quote['id'])->status)->toBe('ready');
});

test('expired quotes and cross activity submissions cannot start a translation or transcription', function () {
    Sanctum::actingAs($this->user);
    $quote = $this->postJson('/api/v1/translations/quotes', ['text' => 'Hello.', 'target_language' => 'fr', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $this->postJson('/api/v1/transcribe', ['quote_id' => $quote['id']])->assertStatus(422);
    BillingQuote::find($quote['id'])->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertStatus(422);
    expect(Translation::count())->toBe(0)->and(Transcription::count())->toBe(0);
});

test('translation history and source transcripts are scoped to the owner', function () {
    $other = User::factory()->create();
    $source = Transcription::factory()->create(['user_id' => $other->id, 'transcript' => 'Private.']);
    $private = Translation::factory()->create(['user_id' => $other->id]);
    Translation::factory()->count(2)->create(['user_id' => $this->user->id]);
    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/translations?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/translations/'.$private->id)->assertNotFound();
    $this->patchJson('/api/v1/translations/'.$private->id, ['translated_text' => 'Stolen.'])->assertNotFound();
    $this->postJson('/api/v1/translations/quotes', ['transcription_id' => $source->id, 'target_language' => 'fr', 'client_key' => (string) Str::uuid()])->assertNotFound();
});

test('unconfigured translation pricing fails safely before reserving credits', function () {
    Sanctum::actingAs($this->user);
    config(['billing.rates.translation.google.nmt.credits' => null]);
    $this->postJson('/api/v1/translations/quotes', ['text' => 'Hello.', 'target_language' => 'fr', 'client_key' => (string) Str::uuid()])->assertStatus(503);
    expect(BillingQuote::count())->toBe(0)->and(UsageCharge::count())->toBe(0);
});
