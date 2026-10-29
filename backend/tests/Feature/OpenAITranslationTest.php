<?php

use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Services\CreditService;
use App\Domain\Translation\Contracts\TranslationExportStorageInterface;
use App\Domain\Translation\Services\ProcessTranslation;
use App\Infrastructure\AI\Translation\Gateways\OpenAITranslationGateway;
use App\Infrastructure\AI\Translation\R2TranslationExportStorage;
use App\Infrastructure\Persistence\Eloquent\Models\BillingQuote;
use App\Infrastructure\Persistence\Eloquent\Models\CreditTransaction;
use App\Infrastructure\Persistence\Eloquent\Models\CreditWallet;
use App\Infrastructure\Persistence\Eloquent\Models\OutboxEvent;
use App\Infrastructure\Persistence\Eloquent\Models\Transcription;
use App\Infrastructure\Persistence\Eloquent\Models\Translation;
use App\Infrastructure\Persistence\Eloquent\Models\UsageCharge;
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
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path) => 'https://downloads.example/'.$path);
    config(['translation.provider' => 'openai', 'translation.openai.model' => 'gpt-4.1-mini',
        'translation.google.key' => null, 'openai.key' => 'test-key', 'openai.endpoint' => 'https://api.openai.com/v1/',
        'openai.text_model' => 'gpt-4.1-mini', 'billing.free_credits' => '0',
        'billing.rates.translation.openai' => ['gpt-4.1-mini' => ['unit' => '1000_characters', 'credits' => '10',
            'provider_cost' => '0.01', 'provider_currency' => 'USD']]]);
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    app(CreditService::class)->purchase($this->user->id, 100000, 'test-funding');
    $this->providerFails = false;
    Http::fake(function ($request) {
        if ($request->url() !== 'https://api.openai.com/v1/responses') {
            throw new RuntimeException('Unexpected translation provider call.');
        }
        if ($this->providerFails) {
            return Http::response(['error' => ['type' => 'server_error']], 503);
        }
        $input = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
        $result = ['texts' => array_reverse(array_map(fn ($item) => ['id' => $item['id'], 'text' => 'ES '.$item['text']], $input['texts'])),
            'detected_language' => $input['source_language'] ?? 'en'];

        return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]]]]]);
    });
});

test('OpenAI translation has a global Nigerian-first catalog without Google credentials', function () {
    $this->getJson('/api/v1/translations/languages')->assertOk()
        ->assertJsonPath('data.0.code', 'yo')->assertJsonPath('data.1.code', 'ig')->assertJsonPath('data.2.code', 'ha')
        ->assertJsonPath('data.3.code', 'en')->assertJsonPath('data.0.nigerian', true)
        ->assertJsonFragment(['code' => 'ja', 'name' => 'Japanese', 'nigerian' => false])
        ->assertJsonFragment(['code' => 'es', 'name' => 'Spanish', 'nigerian' => false]);
    Http::assertNothingSent();
});

test('translation quotes and queued results preserve their OpenAI provider model and owned speaker segments', function () {
    $source = Transcription::factory()->create(['user_id' => $this->user->id, 'name' => 'Interview',
        'transcript' => "Hello.\nReply.", 'segments' => [
            ['start' => 1, 'end' => 2, 'speaker' => 'Ada', 'text' => 'Hello.', 'confidence' => 0.9],
            ['start' => 3, 'end' => 5, 'speaker' => 'Bola', 'text' => 'Reply.', 'confidence' => 0.8],
        ]]);
    $body = ['transcription_id' => $source->id, 'target_language' => 'es', 'client_key' => (string) Str::uuid()];
    $quote = $this->postJson('/api/v1/translations/quotes', $body)->assertOk()->json();
    $savedQuote = BillingQuote::findOrFail($quote['id']);
    expect($savedQuote->provider)->toBe('openai')->and($savedQuote->model)->toBe('gpt-4.1-mini');

    config(['translation.provider' => 'google', 'translation.openai.model' => 'different-model', 'openai.text_model' => 'different-model']);
    $this->postJson('/api/v1/translations/quotes', $body)->assertOk()->assertJsonPath('id', $quote['id']);
    $submitted = $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->assertJsonPath('id', $submitted['id']);
    $record = Translation::findOrFail($submitted['id']);
    $processor = app(ProcessTranslation::class);
    $processor->handle($record->id);
    $processor->handle($record->id);

    expect($record->refresh()->status)->toBe('complete')->and($record->provider)->toBe('openai')->and($record->model)->toBe('gpt-4.1-mini')
        ->and($record->translated_text)->toBe("ES Hello.\nES Reply.")->and($record->detected_language)->toBe('en')
        ->and($record->segments[0])->toMatchArray(['start' => 1, 'end' => 2, 'speaker' => 'Ada', 'text' => 'ES Hello.'])
        ->and($record->segments[1])->toMatchArray(['start' => 3, 'end' => 5, 'speaker' => 'Bola', 'text' => 'ES Reply.'])
        ->and($record->folder_id)->not->toBeNull()->and($record->exports)->toHaveCount(6)
        ->and(Translation::count())->toBe(1)->and(UsageCharge::where('translation_id', $record->id)->sole()->status)->toBe('consumed')
        ->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'TranslationSubmitted')->count())->toBe(1);
    foreach ($record->exports as $export) {
        Storage::disk('r2')->assertExists($export['storage_path']);
    }
    expect($source->refresh()->transcript)->toBe("Hello.\nReply.");
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['model'] === 'gpt-4.1-mini' && $request['store'] === false);
    expect($submitted)->not->toHaveKey('provider');
});

test('OpenAI download retries reuse saved translated text after storage failure', function () {
    $quote = $this->postJson('/api/v1/translations/quotes', ['text' => 'Hello.', 'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $submitted = $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $record = Translation::findOrFail($submitted['id']);
    $storage = app(R2TranslationExportStorage::class);
    $this->mock(TranslationExportStorageInterface::class, function ($mock) use ($storage) {
        $mock->shouldReceive('generate')->once()->andThrow(new RuntimeException('R2 unavailable'));
        $mock->shouldReceive('generate')->once()->andReturnUsing(fn ($translation) => $storage->generate($translation));
    });
    $processor = app(ProcessTranslation::class);
    expect(fn () => $processor->handle($record->id))->toThrow(RuntimeException::class, 'R2 unavailable');
    expect($record->refresh()->translated_text)->toBe('ES Hello.');
    config(['translation.provider' => 'unsupported', 'openai.key' => null]);
    $processor->handle($record->id);
    expect($record->refresh()->status)->toBe('complete')->and(CreditTransaction::where('kind', 'consume')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('exhausted OpenAI translation failure returns reserved credits once', function () {
    $quote = $this->postJson('/api/v1/translations/quotes', ['text' => 'Hello.', 'target_language' => 'es', 'client_key' => (string) Str::uuid()])->assertOk()->json();
    $submitted = $this->postJson('/api/v1/translations', ['quote_id' => $quote['id']])->assertAccepted()->json();
    $record = Translation::findOrFail($submitted['id']);
    $processor = app(ProcessTranslation::class);
    $this->providerFails = true;
    expect(fn () => $processor->handle($record->id))->toThrow(BillingException::class);
    $processor->fail($record->id, 0);
    $processor->fail($record->id, 0);

    expect($record->refresh()->status)->toBe('failed')->and($record->translated_text)->toBeNull()
        ->and(UsageCharge::where('translation_id', $record->id)->sole()->status)->toBe('released')
        ->and(CreditTransaction::where('kind', 'release')->count())->toBe(1)
        ->and(CreditWallet::where('user_id', $this->user->id)->sole()->available_units)->toBe(100000);
    Http::assertSentCount(1);
});

test('OpenAI translation batches long Unicode documents without losing source chunks or item ownership', function () {
    $text = str_repeat('Ẹ káàárọ̀ 世界. ', 1800);
    $result = app(OpenAITranslationGateway::class)->translate([$text, 'Next speaker.'], 'yo', 'es');
    expect($result['texts'][0])->toContain('Ẹ káàárọ̀', '世界')->and($result['texts'][1])->toBe('ES Next speaker.');
    $sourceChunks = [];
    foreach (Http::recorded() as [$request]) {
        $input = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
        $batch = array_column($input['texts'], 'text');
        expect(mb_strlen(implode('', $batch), 'UTF-8'))->toBeLessThanOrEqual(12000)->and(count($batch))->toBeLessThanOrEqual(500);
        foreach ($batch as $chunk) {
            expect(mb_check_encoding($chunk, 'UTF-8'))->toBeTrue();
            $sourceChunks[] = $chunk;
        }
    }
    expect(implode('', $sourceChunks))->toBe($text.'Next speaker.');
});
