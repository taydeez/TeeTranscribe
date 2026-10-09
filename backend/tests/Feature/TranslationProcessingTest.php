<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Contracts\TranslationRepositoryInterface;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\AI\Translation\Gateways\GoogleTranslationGateway;
use App\Infrastructure\AI\Translation\R2TranslationExportStorage;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
use App\Jobs\ProcessTranslation as TranslationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Cache::forget('translation:google:nmt:languages:en');
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path) => 'https://downloads.example/'.$path);
    config(['translation.provider' => 'google', 'translation.google.key' => 'test-key', 'billing.free_credits' => '0', 'billing.rates.translation.google.nmt.credits' => '10']);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 10000, 'test-funding');
    $this->providerFails = false;
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/languages')) {
            return Http::response(['data' => ['languages' => [['language' => 'en', 'name' => 'English'], ['language' => 'fr', 'name' => 'French']]]]);
        }
        if ($this->providerFails) {
            return Http::response(['error' => ['status' => 'UNAVAILABLE']], 503);
        }

        return Http::response(['data' => ['translations' => array_map(fn ($text) => ['translatedText' => htmlspecialchars('FR '.$text, ENT_QUOTES, 'UTF-8'), 'detectedSourceLanguage' => 'en'], $request['q'])]]);
    });
});

function submitTranslationForTest($test, array $input = []): Translation
{
    $quote = $test->postJson('/api/v1/translations/quotes', $input + ['text' => 'Hello & welcome.', 'target_language' => 'fr', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $record = $test->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->json();

    return Translation::findOrFail($record['id']);
}

test('queued processing saves decoded text verifies every export and settles credits once', function () {
    $record = submitTranslationForTest($this);
    $event = OutboxEvent::where('aggregate_id', $record->id)->sole();
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(TranslationJob::class, fn ($job) => $job->translationId === $record->id && $job->outboxEventId === $event->id);
    $job = new TranslationJob($record->id, $event->id);
    $job->handle(app(ProcessTranslation::class), app(TranslationRepositoryInterface::class));
    $job->handle(app(ProcessTranslation::class), app(TranslationRepositoryInterface::class));
    expect($record->refresh()->status)->toBe('complete')->and($record->translated_text)->toBe('FR Hello & welcome.')
        ->and($record->detected_language)->toBe('en')->and($event->refresh()->published_at)->not->toBeNull()
        ->and(UsageCharge::where('translation_id', $record->id)->sole()->status)->toBe('consumed');
    foreach ($record->exports as $export) {
        Storage::disk('r2')->assertExists($export['storage_path']);
    }
    expect($record->exports)->toHaveCount(3)->and(CreditWallet::where('user_id', $this->user->id)->sole()->reserved_units)->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['format'] === 'text' && ! isset($request['source']));
    $this->getJson('/api/v1/translations/'.$record->id)->assertOk()->assertJsonCount(3, 'exports')->assertJsonPath('exports.0.downloadUrl', 'https://downloads.example/'.$record->exports[0]['storage_path']);
});

test('speaker segments preserve names and timestamps and edited exports do not call the provider again', function () {
    $source = Transcription::factory()->create(['user_id' => $this->user->id, 'provider' => 'deepgram', 'name' => 'Meeting', 'transcript' => "Hello.\nReply.", 'segments' => [
        ['start' => 1, 'end' => 2, 'speaker' => 'Ada', 'text' => 'Hello.', 'confidence' => 0.9],
        ['start' => 3, 'end' => 5, 'speaker' => 'Bola', 'text' => 'Reply.', 'confidence' => 0.8],
    ]]);
    $record = submitTranslationForTest($this, ['transcription_id' => $source->id, 'text' => $source->transcript]);
    $processor = app(ProcessTranslation::class);
    $processor->handle($record->id);
    expect($record->refresh()->segments[0])->toMatchArray(['start' => 1, 'end' => 2, 'speaker' => 'Ada', 'text' => 'FR Hello.']);
    expect($record->exports)->toHaveCount(6);
    $body = ['translated_text' => 'Ignored', 'segments' => [['text' => 'Bonjour.', 'speaker' => 'Chidi'], ['text' => 'Merci.', 'speaker' => 'Bola']]];
    $this->patchJson('/api/v1/translations/'.$record->id, $body)->assertOk()->assertJsonPath('status', 'processing');
    $this->patchJson('/api/v1/translations/'.$record->id, $body)->assertOk();
    expect(OutboxEvent::where('aggregate_id', $record->id)->where('event_type', 'TranslationExportsRequested')->count())->toBe(1);
    $processor->handle($record->id);
    expect($record->refresh()->segments[0]['start'])->toBe(1)->and($record->status)->toBe('complete');
    expect(Storage::disk('r2')->get("translations/{$record->id}/revisions/1/speakers/Meeting.txt"))->toBe("Chidi:\nBonjour.\n\nBola:\nMerci.");
    Http::assertSentCount(2);
    expect(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
});

test('transferred unsaved speaker edits retain owned timestamps and subsequent edits queue immediately', function () {
    $source = Transcription::factory()->create(['user_id' => $this->user->id, 'transcript' => 'Original.', 'segments' => [
        ['start' => 2, 'end' => 4, 'speaker' => 'Speaker 1', 'text' => 'Original.', 'confidence' => 0.9],
    ]]);
    $record = submitTranslationForTest($this, ['transcription_id' => $source->id, 'text' => 'Edited.', 'segments' => [['text' => 'Edited.', 'speaker' => 'Ada']]]);
    $processor = app(ProcessTranslation::class);
    $submitted = OutboxEvent::where('aggregate_id', $record->id)->where('event_type', 'TranslationSubmitted')->sole();
    (new TranslationJob($record->id, $submitted->id))->handle($processor, app(TranslationRepositoryInterface::class));
    expect($record->refresh()->segments[0])->toMatchArray(['start' => 2, 'end' => 4, 'text' => 'FR Edited.', 'speaker' => 'Ada']);
    expect($source->refresh()->transcript)->toBe('Original.');
    $this->patchJson('/api/v1/translations/'.$record->id, ['translated_text' => 'First edit.'])->assertOk();
    $event = OutboxEvent::where('aggregate_id', $record->id)->where('event_type', 'TranslationExportsRequested')->sole();
    $event->update(['attempts' => 1, 'published_at' => now()]);
    $processor->handle($record->id);
    $this->patchJson('/api/v1/translations/'.$record->id, ['translated_text' => 'Second edit.'])->assertOk();
    expect($event->refresh()->attempts)->toBe(0)->and($event->published_at)->toBeNull();
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(TranslationJob::class, fn ($job) => $job->outboxEventId === $event->id);
});

test('exhausted provider failures return reserved credits and stop the outbox retry loop', function () {
    $record = submitTranslationForTest($this);
    $event = OutboxEvent::where('aggregate_id', $record->id)->sole();
    $this->providerFails = true;
    $job = new TranslationJob($record->id, $event->id);
    expect(fn () => $job->handle(app(ProcessTranslation::class), app(TranslationRepositoryInterface::class)))->toThrow(BillingException::class);
    $job->failed(new RuntimeException('Provider unavailable'));
    $job->failed(new RuntimeException('Duplicate failure'));
    expect($record->refresh()->status)->toBe('failed')->and($event->refresh()->published_at)->not->toBeNull()
        ->and(UsageCharge::where('translation_id', $record->id)->sole()->status)->toBe('released')
        ->and(CreditWallet::where('user_id', $this->user->id)->sole()->available_units)->toBe(10000)
        ->and(CreditTransaction::where('kind', 'release')->count())->toBe(1);
});

test('export retries reuse saved translation text without another API call or charge', function () {
    $record = submitTranslationForTest($this);
    $storage = app(R2TranslationExportStorage::class);
    $this->mock(TranslationExportStorageInterface::class, function ($mock) use ($storage): void {
        $mock->shouldReceive('generate')->once()->andThrow(new RuntimeException('R2 unavailable'));
        $mock->shouldReceive('generate')->once()->andReturnUsing(fn ($translation) => $storage->generate($translation));
    });
    $processor = app(ProcessTranslation::class);
    expect(fn () => $processor->handle($record->id))->toThrow(RuntimeException::class, 'R2 unavailable');
    expect($record->refresh()->translated_text)->toBe('FR Hello & welcome.');
    $processor->handle($record->id);
    expect($record->refresh()->status)->toBe('complete')->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('a result edited during export upload cannot publish or fail an older revision', function () {
    $record = Translation::factory()->create(['user_id' => $this->user->id, 'status' => 'processing', 'translated_text' => 'Old text.', 'exports' => []]);
    $this->mock(TranslationExportStorageInterface::class, function ($mock) use ($record): void {
        $mock->shouldReceive('generate')->once()->andReturnUsing(function () use ($record) {
            $record->update(['export_revision' => 1, 'translated_text' => 'New text.']);

            return [['format' => 'txt', 'variant' => 'plain', 'status' => 'completed', 'storage_path' => 'old.txt']];
        });
    });
    $processor = app(ProcessTranslation::class);
    expect($processor->handle($record->id))->toBeNull();
    $processor->fail($record->id, 0);
    expect($record->refresh()->status)->toBe('processing')->and($record->exports)->toBe([])->and($record->export_revision)->toBe(1);
    Http::assertNothingSent();
});

test('large multilingual text is split into bounded requests without losing text or breaking Unicode', function () {
    $text = str_repeat('Ẹ káàárọ̀ 世界. ', 1800);
    $result = app(GoogleTranslationGateway::class)->translate([$text], 'en', 'fr');
    expect($result['texts'][0])->toContain('Ẹ káàárọ̀', '世界');
    Http::assertSent(fn ($request) => $request['source'] === 'en' && count($request['q']) <= 128);
    $sent = [];
    foreach (Http::recorded() as [$request]) {
        expect(count($request['q']))->toBeLessThanOrEqual(128)->and(strlen(json_encode($request['q'])))->toBeLessThanOrEqual(90000);
        foreach ($request['q'] as $chunk) {
            expect(mb_check_encoding($chunk, 'UTF-8'))->toBeTrue();
            $sent[] = $chunk;
        }
    }
    expect(implode('', $sent))->toBe($text);
});
